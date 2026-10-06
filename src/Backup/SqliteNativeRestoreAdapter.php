<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Backup;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\NativeBackupFormat;
use Drupal\dbtng_migrator\Model\NativeRestoreRequest;
use Drupal\dbtng_migrator\Model\ResolvedDatabase;

/**
 * Validates and atomically publishes an isolated SQLite restore artifact. */
final class SqliteNativeRestoreAdapter {

  public function restore(ResolvedDatabase $standby, NativeRestoreRequest $request): void {
    if ($standby->role->value !== 'standby' || $standby->engine !== DatabaseEngine::Sqlite
      || !in_array($request->format, [NativeBackupFormat::SqliteDatabase, NativeBackupFormat::SqliteDatabaseGzip], TRUE)) {
      throw new DbtngException('SQLite native restore accepts only a SQLite standby and a SQLite database artifact.');
    }
    $sourcePath = $this->validateArtifact($request);
    $targetPath = $standby->databasePath;
    $privateRoot = Settings::get('file_private_path');
    if ($targetPath === NULL || $targetPath === '' || $targetPath === ':memory:' || !is_string($privateRoot) || $privateRoot === '') {
      throw new DbtngException('SQLite restore requires a file-backed private standby.');
    }
    if (realpath($sourcePath) === realpath($targetPath)) {
      throw new DbtngException('SQLite restore artifact and standby path must be distinct.');
    }
    if (is_link(dirname($targetPath)) || !is_dir(dirname($targetPath))) {
      throw new DbtngException('SQLite standby path or parent directory is unsafe.');
    }
    $directory = realpath(dirname($targetPath));
    $private = realpath($privateRoot);
    if ($directory === FALSE || $private === FALSE
      || !str_starts_with($directory . DIRECTORY_SEPARATOR, rtrim($private, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
      || (defined('DRUPAL_ROOT') && str_starts_with($directory . DIRECTORY_SEPARATOR, rtrim((string) realpath(DRUPAL_ROOT), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
      throw new DbtngException('SQLite standby must remain under private storage and outside the webroot.');
    }
    if (is_link($targetPath)) {
      $publishedPath = realpath($targetPath);
      if ($publishedPath === FALSE
        || !str_starts_with($publishedPath, $directory . DIRECTORY_SEPARATOR . 'generations' . DIRECTORY_SEPARATOR)
        || !is_file($publishedPath)) {
        throw new DbtngException('SQLite restore pointer must resolve to a private DBTNG generation.');
      }
    }
    $directoryMode = fileperms($directory);
    if ($directoryMode === FALSE || ($directoryMode & 0077) !== 0) {
      throw new DbtngException('SQLite standby directory must be owner-private (0700 or stricter).');
    }

    $candidate = tempnam($directory, '.dbtng-restore-');
    if ($candidate === FALSE || !chmod($candidate, 0600)) {
      throw new DbtngException('Unable to create an isolated SQLite restore candidate.');
    }
    try {
      $this->copyArtifact($sourcePath, $candidate, $request->format->compressed());
      $this->validateDatabase($candidate);
      Database::closeConnection(NULL, $standby->connectionKey);
      if (!rename($candidate, $targetPath)) {
        throw new DbtngException('Unable to atomically publish the validated SQLite standby.');
      }
      if (!chmod($targetPath, 0600)) {
        throw new DbtngException('Unable to protect the published SQLite standby database.');
      }
      foreach ([$targetPath . '-wal', $targetPath . '-shm'] as $sidecar) {
        if (is_link($sidecar)) {
          throw new DbtngException('SQLite standby sidecar path is unsafe.');
        }
        if (is_file($sidecar) && !unlink($sidecar)) {
          throw new DbtngException('Unable to remove a stale SQLite standby sidecar.');
        }
      }
    }
    catch (\Throwable $exception) {
      if (is_file($candidate)) {
        unlink($candidate);
      }
      if ($exception instanceof DbtngException) {
        throw $exception;
      }
      throw new DbtngException('SQLite native restore failed; the candidate was not published.', 0, $exception);
    }
  }

  /**
   * Validates restore input before standby preparation.
   */
  public function validateArtifact(NativeRestoreRequest $request): string {
    return $this->validatePrivateArtifact($request->backupPath, $request->format);
  }

  private function validatePrivateArtifact(string $path, NativeBackupFormat $format): string {
    if (is_link($path) || !is_file($path) || ($permissions = fileperms($path)) === FALSE || ($permissions & 0077) !== 0) {
      throw new DbtngException('Restore artifact must be a regular owner-private file.');
    }
    $real = realpath($path);
    if ($real === FALSE || (defined('DRUPAL_ROOT') && str_starts_with($real, rtrim((string) realpath(DRUPAL_ROOT), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
      throw new DbtngException('Restore artifact must be outside the Drupal webroot.');
    }
    if ($format->compressed()) {
      $input = gzopen($real, 'rb');
      $magic = $input === FALSE ? FALSE : gzread($input, 16);
      if ($input !== FALSE) {
        gzclose($input);
      }
      if (!is_string($magic) || !str_starts_with($magic, "SQLite format 3\0")) {
        throw new DbtngException('Compressed restore artifact does not contain a SQLite database.');
      }
    }
    else {
      $handle = fopen($real, 'rb');
      if ($handle === FALSE) {
        throw new DbtngException('Restore artifact cannot be opened.');
      }
      $header = fread($handle, 16);
      fclose($handle);
      if (!is_string($header) || !str_starts_with($header, "SQLite format 3\0")) {
        throw new DbtngException('Restore artifact does not have a SQLite database header.');
      }
    }
    if ($format === NativeBackupFormat::SqliteDatabaseGzip && !str_ends_with(strtolower($real), '.sqlite.gz')) {
      throw new DbtngException('Compressed SQLite restore artifacts must use the .sqlite.gz suffix.');
    }
    if ($format === NativeBackupFormat::SqliteDatabase && !str_ends_with(strtolower($real), '.sqlite')) {
      throw new DbtngException('SQLite restore artifacts must use the .sqlite suffix.');
    }
    return $real;
  }

  private function copyArtifact(string $source, string $destination, bool $compressed): void {
    $input = $compressed ? gzopen($source, 'rb') : fopen($source, 'rb');
    $output = fopen($destination, 'wb');
    if ($input === FALSE || $output === FALSE) {
      if (is_resource($input)) {
        $compressed ? gzclose($input) : fclose($input);
      }
      if (is_resource($output)) {
        fclose($output);
      }
      throw new DbtngException('Unable to stream the SQLite restore artifact.');
    }
    try {
      while ($compressed ? !gzeof($input) : !feof($input)) {
        $chunk = $compressed ? gzread($input, 1024 * 1024) : fread($input, 1024 * 1024);
        if ($chunk === FALSE || ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk))) {
          throw new DbtngException('SQLite restore artifact decompression or write failed.');
        }
      }
      if ($compressed) {
        $gzipValid = gzclose($input);
        $input = FALSE;
        if (!$gzipValid) {
          throw new DbtngException('Compressed SQLite restore artifact is corrupt.');
        }
      }
    }
    finally {
      if ($input !== FALSE) {
        $compressed ? gzclose($input) : fclose($input);
      }
      fclose($output);
    }
    if (!chmod($destination, 0600)) {
      throw new DbtngException('Unable to protect the private SQLite restore candidate.');
    }
  }

  private function validateDatabase(string $path): void {
    $database = new \SQLite3($path, SQLITE3_OPEN_READONLY);
    $database->enableExceptions(TRUE);
    if (!class_exists(Unicode::class) || !$database->createCollation('NOCASE_UTF8', [Unicode::class, 'strcasecmp'])) {
      $database->close();
      throw new DbtngException('Unable to register Drupal SQLite collation for restore validation.');
    }
    try {
      if ($database->querySingle('PRAGMA integrity_check') !== 'ok') {
        throw new DbtngException('Restored SQLite candidate failed PRAGMA integrity_check.');
      }
      $foreignKeys = $database->query('PRAGMA foreign_key_check');
      if ($foreignKeys !== FALSE && $foreignKeys->fetchArray(SQLITE3_NUM) !== FALSE) {
        throw new DbtngException('Restored SQLite candidate failed PRAGMA foreign_key_check.');
      }
    }
    finally {
      $database->close();
    }
  }

}
