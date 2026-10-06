<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Backup;

use Drupal\Component\Utility\Unicode;
use Drupal\dbtng_migrator\Contract\NativeBackupAdapterInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\BackupCompression;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\NativeBackupArtifact;
use Drupal\dbtng_migrator\Model\NativeBackupFormat;
use Drupal\dbtng_migrator\Model\ResolvedDatabase;

/**
 * Creates a consistent snapshot through PHP SQLite3::backup().
 *
 * This calls SQLite's Online Backup API; it does not copy the live main file,
 * which would omit committed pages held in a WAL sidecar.
 */
final class SqliteNativeBackupAdapter implements NativeBackupAdapterInterface {

  public function supports(DatabaseEngine $engine): bool {
    return $engine === DatabaseEngine::Sqlite;
  }

  public function create(ResolvedDatabase $database, BackupCompression $compression, string $directory): NativeBackupArtifact {
    if (!$this->supports($database->engine)) {
      throw new \InvalidArgumentException('SQLite native backup requires a SQLite connection.');
    }
    if (!class_exists(\SQLite3::class)) {
      throw new DbtngException('PHP SQLite3::backup() is unavailable.');
    }
    $sourcePath = $database->databasePath;
    if ($sourcePath === NULL || $sourcePath === '' || $sourcePath === ':memory:' || !is_file($sourcePath)) {
      throw new DbtngException('The configured SQLite database is not a file that can be backed up.');
    }
    $connectionOptions = $database->connection->getConnectionOptions();
    if (($connectionOptions['prefix'] ?? '') !== '') {
      throw new DbtngException('SQLite backup does not support databases attached through a table prefix.');
    }

    $suffix = bin2hex(random_bytes(4));
    $baseName = sprintf('dbtng-%s-%s-%s.sqlite', $database->role->value, gmdate('Ymd\THis\Z'), $suffix);
    $rawPath = $directory . DIRECTORY_SEPARATOR . $baseName;
    $artifactPath = $compression === BackupCompression::Gzip ? $rawPath . '.gz' : $rawPath;
    $source = NULL;
    $destination = NULL;
    $oldUmask = umask(0077);
    try {
      $source = new \SQLite3($sourcePath, SQLITE3_OPEN_READONLY);
      $source->enableExceptions(TRUE);
      $source->busyTimeout(5000);
      $this->registerDrupalCollations($source);
      $destination = new \SQLite3($rawPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
      $destination->enableExceptions(TRUE);
      $destination->busyTimeout(5000);
      $this->registerDrupalCollations($destination);
      if (!$source->backup($destination)) {
        throw new DbtngException('SQLite Online Backup API failed.');
      }
      if ($destination->querySingle('PRAGMA integrity_check') !== 'ok') {
        throw new DbtngException('The generated SQLite snapshot failed PRAGMA integrity_check.');
      }
      $destination->close();
      $destination = NULL;
      $source->close();
      $source = NULL;
      chmod($rawPath, 0600);
      if ($compression === BackupCompression::Gzip) {
        $this->gzipFile($rawPath, $artifactPath);
        unlink($rawPath);
      }
      $hash = hash_file('sha256', $artifactPath);
      $bytes = filesize($artifactPath);
      if (!is_string($hash) || $bytes === FALSE || $bytes < 1) {
        throw new DbtngException('The completed SQLite backup could not be checksummed.');
      }
      chmod($artifactPath, 0600);
      $format = $compression === BackupCompression::Gzip ? NativeBackupFormat::SqliteDatabaseGzip : NativeBackupFormat::SqliteDatabase;
      return new NativeBackupArtifact(
        DatabaseEngine::Sqlite,
        $database->role,
        $format,
        $artifactPath,
        basename($artifactPath),
        $compression === BackupCompression::Gzip ? 'application/gzip' : 'application/vnd.sqlite3',
        $bytes,
        $hash,
      );
    }
    catch (\Throwable $exception) {
      if ($source instanceof \SQLite3) {
        $source->close();
      }
      if ($destination instanceof \SQLite3) {
        $destination->close();
      }
      if (is_file($rawPath)) {
        unlink($rawPath);
      }
      if (is_file($artifactPath)) {
        unlink($artifactPath);
      }
      if ($exception instanceof DbtngException) {
        throw $exception;
      }
      throw new DbtngException('Native SQLite backup failed; no artifact was retained.', 0, $exception);
    }
    finally {
      umask($oldUmask);
    }
  }

  /**
   * Registers Drupal's SQLite collation for snapshot integrity checks.
   */
  private function registerDrupalCollations(\SQLite3 $database): void {
    if (!class_exists(Unicode::class)) {
      throw new DbtngException('Drupal Unicode collation support is unavailable for the SQLite snapshot.');
    }
    $callback = [Unicode::class, 'strcasecmp'];
    $registered = $database->createCollation('NOCASE_UTF8', $callback);
    if (!$registered) {
      throw new DbtngException('Unable to register Drupal NOCASE_UTF8 collation for SQLite backup validation.');
    }
  }

  private function gzipFile(string $sourcePath, string $destinationPath): void {
    $source = fopen($sourcePath, 'rb');
    $destination = gzopen($destinationPath, 'wb9');
    if ($source === FALSE || $destination === FALSE) {
      if (is_resource($source)) {
        fclose($source);
      }
      if (is_resource($destination)) {
        gzclose($destination);
      }
      throw new DbtngException('Unable to create the compressed SQLite backup artifact.');
    }
    try {
      while (!feof($source)) {
        $chunk = fread($source, 1024 * 1024);
        if ($chunk === FALSE || ($chunk !== '' && gzwrite($destination, $chunk) !== strlen($chunk))) {
          throw new DbtngException('Unable to compress the SQLite backup artifact.');
        }
      }
    }
    finally {
      fclose($source);
      gzclose($destination);
    }
  }

}
