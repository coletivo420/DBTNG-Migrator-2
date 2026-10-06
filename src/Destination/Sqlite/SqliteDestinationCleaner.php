<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Destination\Sqlite;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\DestinationCleanerInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseRole;
use Drupal\dbtng_migrator\Model\DatabaseTopology;

/**
 * Replaces a proven standby SQLite file with an empty private database. */
final class SqliteDestinationCleaner implements DestinationCleanerInterface {

  public function __construct(private readonly DatabaseTopologyResolver $resolver) {}

  public function clearStandby(DatabaseTopology $topology): void {
    $resolved = $this->resolver->resolve();
    $standby = $resolved->standby;
    if ($standby->role !== DatabaseRole::Standby
      || $standby->engine !== DatabaseEngine::Sqlite
      || $resolved->primary->identity === $standby->identity
      || $standby->connectionKey === $resolved->primary->connectionKey
      || !$this->sameTopology($topology, $resolved->topology)) {
      throw new DbtngException('SQLite clear refused because the target is not a proven, distinct standby.');
    }
    $path = $standby->databasePath;
    $privateRoot = Settings::get('file_private_path');
    if ($path === NULL || $path === '' || $path === ':memory:' || !is_string($privateRoot) || $privateRoot === '') {
      throw new DbtngException('SQLite clear requires a file-backed standby under private storage.');
    }
    if (is_link(dirname($path)) || !is_dir(dirname($path))) {
      throw new DbtngException('SQLite standby path or parent directory is unsafe.');
    }
    $directory = realpath(dirname($path));
    $private = realpath($privateRoot);
    if ($directory === FALSE || $private === FALSE
      || !str_starts_with($directory . DIRECTORY_SEPARATOR, rtrim($private, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
      || (defined('DRUPAL_ROOT') && str_starts_with($directory . DIRECTORY_SEPARATOR, rtrim((string) realpath(DRUPAL_ROOT), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
      throw new DbtngException('SQLite standby must be stored outside the webroot under the configured private directory.');
    }
    if (is_link($path)) {
      $published = realpath($path);
      if ($published === FALSE || !str_starts_with($published, $directory . DIRECTORY_SEPARATOR . 'generations' . DIRECTORY_SEPARATOR) || !is_file($published)) {
        throw new DbtngException('SQLite standby pointer must resolve to a private DBTNG generation.');
      }
    }
    $directoryMode = fileperms($directory);
    if ($directoryMode === FALSE || ($directoryMode & 0077) !== 0) {
      throw new DbtngException('SQLite standby directory must be owner-private (0700 or stricter).');
    }
    if (file_exists($path) && !is_file($path)) {
      throw new DbtngException('SQLite standby is not a regular file.');
    }
    if (file_exists($path) && ($permissions = fileperms($path)) !== FALSE && ($permissions & 0077) !== 0) {
      throw new DbtngException('SQLite standby file must be owner-private (0600 or stricter).');
    }

    $temporary = tempnam($directory, '.dbtng-empty-');
    if ($temporary === FALSE) {
      throw new DbtngException('Unable to create an isolated SQLite standby replacement.');
    }
    if (!chmod($temporary, 0600)) {
      throw new DbtngException('Unable to protect the empty SQLite standby replacement.');
    }
    try {
      $empty = new \SQLite3($temporary, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
      $empty->enableExceptions(TRUE);
      if (!class_exists(Unicode::class) || !$empty->createCollation('NOCASE_UTF8', [Unicode::class, 'strcasecmp'])) {
        throw new DbtngException('Unable to register Drupal SQLite collation for standby validation.');
      }
      if ($empty->querySingle('PRAGMA integrity_check') !== 'ok') {
        throw new DbtngException('The empty SQLite replacement failed integrity_check.');
      }
      $empty->close();

      // Close only the named standby connection; the primary connection and
      // Drupal's active/default key are never changed.
      Database::closeConnection(NULL, $standby->connectionKey);
      if (!rename($temporary, $path)) {
        throw new DbtngException('Unable to atomically publish the empty SQLite standby file.');
      }
      if (!chmod($path, 0600)) {
        throw new DbtngException('Unable to protect the published SQLite standby file.');
      }
      foreach ([$path . '-wal', $path . '-shm'] as $sidecar) {
        if (is_link($sidecar)) {
          throw new DbtngException('SQLite standby sidecar path is unsafe.');
        }
        if (is_file($sidecar) && !unlink($sidecar)) {
          throw new DbtngException('Unable to remove a stale SQLite standby sidecar.');
        }
      }
    }
    catch (\Throwable $exception) {
      if (is_file($temporary)) {
        unlink($temporary);
      }
      if ($exception instanceof DbtngException) {
        throw $exception;
      }
      throw new DbtngException('SQLite standby replacement failed.', 0, $exception);
    }
  }

  private function sameTopology(DatabaseTopology $expected, DatabaseTopology $actual): bool {
    return $expected->primaryEngine === $actual->primaryEngine
      && $expected->standbyEngine === $actual->standbyEngine
      && $expected->primaryConnectionKey === $actual->primaryConnectionKey
      && $expected->standbyConnectionKey === $actual->standbyConnectionKey;
  }

}
