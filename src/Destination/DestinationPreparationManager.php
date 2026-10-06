<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Destination;

use Drupal\Component\Utility\Unicode;
use Drupal\dbtng_migrator\Backup\NativeBackupManager;
use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\DestinationCleanerInterface;
use Drupal\dbtng_migrator\Exception\DestinationNotEmptyException;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseRole;
use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\DestinationPreparationResult;
use Drupal\dbtng_migrator\Model\DestinationState;
use Drupal\dbtng_migrator\Model\NativeBackupArtifact;
use Drupal\dbtng_migrator\Model\NativeBackupRequest;
use Drupal\dbtng_migrator\Model\NonEmptyDestinationPolicy;

/**
 * Applies explicit non-empty policies after proving the target is standby. */
final class DestinationPreparationManager {

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly DestinationStateInspectionManager $inspector,
    private readonly NativeBackupManager $backups,
    private readonly DestinationCleanerInterface $mysqlCleaner,
    private readonly DestinationCleanerInterface $sqliteCleaner,
  ) {}

  public function prepare(DatabaseTopology $topology, NonEmptyDestinationPolicy $policy, bool $publishReplacementAtomically = FALSE): DestinationPreparationResult {
    $resolved = $this->resolver->resolve();
    $this->assertCurrentTopology($topology, $resolved->topology);
    if ($resolved->primary->identity === $resolved->standby->identity
      || $resolved->standby->role !== DatabaseRole::Standby
      || $resolved->standby->connectionKey === $resolved->primary->connectionKey
      || $resolved->standby->engine !== $topology->standbyEngine) {
      throw new DbtngException('Destructive preparation refused because the standby identity could not be proven distinct.');
    }

    $state = $this->inspector->inspect($resolved->standby->connection)->state;
    if ($state === DestinationState::Empty) {
      return new DestinationPreparationResult($state, $policy);
    }
    if ($policy === NonEmptyDestinationPolicy::Abort) {
      throw new DestinationNotEmptyException('The standby contains existing state; no changes were made. Select an explicit standby preparation policy.');
    }

    $backup = NULL;
    if ($policy === NonEmptyDestinationPolicy::BackupThenClear) {
      $backup = $this->backups->create($topology, new NativeBackupRequest(DatabaseRole::Standby));
      $this->verifySafetyArtifact($backup, $resolved->standby->engine);
    }

    $deferSqliteClear = $publishReplacementAtomically && $resolved->standby->engine === DatabaseEngine::Sqlite;
    if (!$deferSqliteClear) {
      $cleaner = $resolved->standby->engine === DatabaseEngine::Sqlite ? $this->sqliteCleaner : $this->mysqlCleaner;
      $cleaner->clearStandby($topology);
    }

    // SQLite replacement closes the old named connection. Resolve again so
    // the verification observes the published file rather than its old inode.
    $after = $this->resolver->resolve();
    $this->assertCurrentTopology($topology, $after->topology);
    if ($after->primary->identity === $after->standby->identity
      || (!$deferSqliteClear && $this->inspector->inspect($after->standby->connection)->state !== DestinationState::Empty)) {
      throw new DbtngException('Standby preparation did not produce a verified empty destination.');
    }

    return new DestinationPreparationResult($state, $policy, $backup);
  }

  private function assertCurrentTopology(DatabaseTopology $expected, DatabaseTopology $actual): void {
    if ($expected->primaryEngine !== $actual->primaryEngine
      || $expected->standbyEngine !== $actual->standbyEngine
      || $expected->primaryConnectionKey !== $actual->primaryConnectionKey
      || $expected->standbyConnectionKey !== $actual->standbyConnectionKey) {
      throw new DbtngException('The requested operation no longer matches the configured topology.');
    }
  }

  private function verifySafetyArtifact(NativeBackupArtifact $artifact, DatabaseEngine $engine): void {
    if ($artifact->role !== DatabaseRole::Standby || $artifact->engine !== $engine
      || $artifact->bytes < 1 || !is_file($artifact->path)
      || filesize($artifact->path) !== $artifact->bytes
      || !hash_equals($artifact->sha256, (string) hash_file('sha256', $artifact->path))) {
      throw new DbtngException('The standby safety backup failed artifact, size or SHA-256 verification; no clear was attempted.');
    }
    if ($artifact->format->compressed()) {
      $stream = gzopen($artifact->path, 'rb');
      if ($stream === FALSE) {
        throw new DbtngException('The standby safety backup is not a readable gzip stream; no clear was attempted.');
      }
      try {
        while (!gzeof($stream)) {
          if (gzread($stream, 1024 * 1024) === FALSE) {
            throw new DbtngException('The standby safety backup failed gzip validation; no clear was attempted.');
          }
        }
      }
      finally {
        gzclose($stream);
      }
    }
    if ($engine === DatabaseEngine::Sqlite) {
      $path = $artifact->path;
      if ($artifact->format->compressed()) {
        $temporary = tempnam(dirname($path), '.dbtng-check-');
        if ($temporary === FALSE) {
          throw new DbtngException('Unable to validate the standby SQLite safety backup; no clear was attempted.');
        }
        chmod($temporary, 0600);
        $input = gzopen($path, 'rb');
        $output = fopen($temporary, 'wb');
        if ($input === FALSE || $output === FALSE) {
          if (is_resource($input)) {
            gzclose($input);
          }
          if (is_resource($output)) {
            fclose($output);
          }
          unlink($temporary);
          throw new DbtngException('Unable to validate the standby SQLite safety backup; no clear was attempted.');
        }
        try {
          while (!gzeof($input)) {
            $chunk = gzread($input, 1024 * 1024);
            if ($chunk === FALSE || ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk))) {
              throw new DbtngException('Unable to validate the standby SQLite safety backup; no clear was attempted.');
            }
          }
        }
        finally {
          gzclose($input);
          fclose($output);
        }
        $path = $temporary;
      }
      try {
        $database = new \SQLite3($path, SQLITE3_OPEN_READONLY);
        $database->enableExceptions(TRUE);
        if (!class_exists(Unicode::class) || !$database->createCollation('NOCASE_UTF8', [Unicode::class, 'strcasecmp'])) {
          throw new DbtngException('Unable to register Drupal collation while validating the SQLite safety backup.');
        }
        if ($database->querySingle('PRAGMA integrity_check') !== 'ok') {
          throw new DbtngException('The standby SQLite safety backup failed integrity_check; no clear was attempted.');
        }
        $database->close();
      }
      finally {
        if ($path !== $artifact->path && is_file($path)) {
          unlink($path);
        }
      }
    }
  }

}
