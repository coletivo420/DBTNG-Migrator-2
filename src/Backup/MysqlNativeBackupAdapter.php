<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Backup;

use Drupal\dbtng_migrator\Contract\NativeBackupAdapterInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\BackupCompression;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseProduct;
use Drupal\dbtng_migrator\Model\NativeBackupArtifact;
use Drupal\dbtng_migrator\Model\NativeBackupFormat;
use Drupal\dbtng_migrator\Model\ResolvedDatabase;

/**
 * Streams a consistent native SQL dump to a private file.
 *
 * Upstream basis: Drush 14.x SqlMysql/SqlMariaDB behavior (single-transaction,
 * quick streaming, MariaDB client selection and protected defaults file).
 * DBTNG uses an argv-array proc_open call and does not copy upstream code.
 */
final class MysqlNativeBackupAdapter implements NativeBackupAdapterInterface {

  public function supports(DatabaseEngine $engine): bool {
    return $engine === DatabaseEngine::MysqlFamily;
  }

  public function create(ResolvedDatabase $database, BackupCompression $compression, string $directory): NativeBackupArtifact {
    if (!$this->supports($database->engine)) {
      throw new \InvalidArgumentException('MySQL native backup requires a MySQL-family connection.');
    }
    $options = $database->connection->getConnectionOptions();
    $databaseName = (string) ($options['database'] ?? '');
    if ($databaseName === '') {
      throw new DbtngException('The MySQL connection does not identify a database to back up.');
    }
    $binary = $this->findDumpBinary($database->product);
    if ($binary === NULL) {
      throw new DbtngException(sprintf('No native dump client is installed for %s.', $database->label()));
    }
    $suffix = bin2hex(random_bytes(4));
    $baseName = sprintf('dbtng-%s-%s-%s.sql', $database->role->value, gmdate('Ymd\THis\Z'), $suffix);
    $rawPath = $directory . DIRECTORY_SEPARATOR . $baseName;
    $artifactPath = $compression === BackupCompression::Gzip ? $rawPath . '.gz' : $rawPath;
    $credentialPath = tempnam(sys_get_temp_dir(), 'dbtng-mysql-');
    if ($credentialPath === FALSE || !chmod($credentialPath, 0600)) {
      if (is_string($credentialPath)) {
        unlink($credentialPath);
      }
      throw new DbtngException('Unable to create a protected temporary database credential file.');
    }
    $stderrPath = $directory . DIRECTORY_SEPARATOR . '.dbtng-stderr-' . $suffix;
    $oldUmask = umask(0077);
    try {
      if (file_put_contents($credentialPath, $this->defaultsFileContents($options), LOCK_EX) === FALSE) {
        throw new DbtngException('Unable to write the protected database credential file.');
      }
      chmod($credentialPath, 0600);
      if (file_put_contents($rawPath, '', LOCK_EX) === FALSE || !chmod($rawPath, 0600)) {
        throw new DbtngException('Unable to create the private native backup artifact.');
      }
      $command = [
        $binary,
        '--defaults-extra-file=' . $credentialPath,
        '--single-transaction',
        '--quick',
        '--skip-lock-tables',
        '--routines',
        '--events',
        '--triggers',
        '--no-tablespaces',
        '--default-character-set=utf8mb4',
        $databaseName,
      ];
      $process = proc_open($command, [
        0 => ['file', '/dev/null', 'rb'],
        1 => ['file', $rawPath, 'wb'],
        2 => ['file', $stderrPath, 'wb'],
      ], $pipes, NULL, NULL, ['bypass_shell' => TRUE]);
      if (!is_resource($process)) {
        throw new DbtngException('Unable to start the native database dump client.');
      }
      $exitCode = proc_close($process);
      if ($exitCode !== 0 || !is_file($rawPath) || filesize($rawPath) < 1) {
        throw new DbtngException(sprintf('The native database dump client failed with exit code %d.', $exitCode));
      }
      if ($compression === BackupCompression::Gzip) {
        $this->gzipFile($rawPath, $artifactPath);
        unlink($rawPath);
      }
      $hash = hash_file('sha256', $artifactPath);
      $bytes = filesize($artifactPath);
      if (!is_string($hash) || $bytes === FALSE || $bytes < 1) {
        throw new DbtngException('The completed native backup could not be checksummed.');
      }
      chmod($artifactPath, 0600);
      $format = $compression === BackupCompression::Gzip ? NativeBackupFormat::MysqlSqlGzip : NativeBackupFormat::MysqlSql;
      return new NativeBackupArtifact(
        DatabaseEngine::MysqlFamily,
        $database->role,
        $format,
        $artifactPath,
        basename($artifactPath),
        $compression === BackupCompression::Gzip ? 'application/gzip' : 'application/sql',
        $bytes,
        $hash,
      );
    }
    catch (\Throwable $exception) {
      if (is_file($rawPath)) {
        unlink($rawPath);
      }
      if (is_file($artifactPath)) {
        unlink($artifactPath);
      }
      if ($exception instanceof DbtngException) {
        throw $exception;
      }
      throw new DbtngException('Native MySQL backup failed; no artifact was retained.', 0, $exception);
    }
    finally {
      umask($oldUmask);
      if (is_file($credentialPath)) {
        unlink($credentialPath);
      }
      if (is_file($stderrPath)) {
        unlink($stderrPath);
      }
    }
  }

  /**
   * Builds a protected MySQL option file without exposing secrets to argv.
   *
   * @param array<string, mixed> $options
   *   Drupal connection options.
   */
  private function defaultsFileContents(array $options): string {
    $lines = ['[client]'];
    $optionNames = [
      'username' => 'user',
      'password' => 'password',
      'host' => 'host',
      'port' => 'port',
      'unix_socket' => 'socket',
    ];
    foreach ($optionNames as $source => $destination) {
      if (isset($options[$source]) && is_scalar($options[$source])) {
        $value = (string) $options[$source];
        $value = str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '\\r', '\\n'], $value);
        $lines[] = $destination . ' = "' . $value . '"';
      }
    }
    return implode("\n", $lines) . "\n";
  }

  private function findDumpBinary(DatabaseProduct $product): ?string {
    $names = $product === DatabaseProduct::MariaDb ? ['mariadb-dump'] : ['mysqldump'];
    if ($product === DatabaseProduct::MariaDb) {
      $names[] = 'mysqldump';
    }
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
      foreach ($names as $name) {
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (is_file($path) && is_executable($path)) {
          return $path;
        }
      }
    }
    return NULL;
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
      throw new DbtngException('Unable to create the compressed backup artifact.');
    }
    try {
      while (!feof($source)) {
        $chunk = fread($source, 1024 * 1024);
        if ($chunk === FALSE || ($chunk !== '' && gzwrite($destination, $chunk) !== strlen($chunk))) {
          throw new DbtngException('Unable to compress the native backup artifact.');
        }
      }
    }
    finally {
      fclose($source);
      gzclose($destination);
    }
  }

}
