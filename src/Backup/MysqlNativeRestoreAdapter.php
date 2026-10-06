<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Backup;

use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseProduct;
use Drupal\dbtng_migrator\Model\NativeBackupFormat;
use Drupal\dbtng_migrator\Model\NativeRestoreRequest;
use Drupal\dbtng_migrator\Model\ResolvedDatabase;

/**
 * Streams a verified MySQL-family SQL artifact to the native client. */
final class MysqlNativeRestoreAdapter {

  public function restore(ResolvedDatabase $standby, NativeRestoreRequest $request): void {
    if ($standby->role->value !== 'standby' || $standby->engine !== DatabaseEngine::MysqlFamily
      || !in_array($request->format, [NativeBackupFormat::MysqlSql, NativeBackupFormat::MysqlSqlGzip], TRUE)) {
      throw new DbtngException('MySQL native restore accepts only a MySQL-family standby and a MySQL SQL artifact.');
    }
    $path = $this->validatePrivateArtifact($request->backupPath, $request->format);
    $binary = $this->client($standby->product);
    if ($binary === NULL) {
      throw new DbtngException('No compatible native MySQL client is installed.');
    }
    $options = $standby->connection->getConnectionOptions();
    $database = (string) ($options['database'] ?? '');
    if ($database === '') {
      throw new DbtngException('The MySQL standby database identity is missing.');
    }

    $credential = tempnam(sys_get_temp_dir(), 'dbtng-restore-');
    $stderr = tempnam(sys_get_temp_dir(), 'dbtng-restore-stderr-');
    if ($credential === FALSE || $stderr === FALSE || !chmod($credential, 0600) || !chmod($stderr, 0600)) {
      if (is_string($credential) && is_file($credential)) {
        unlink($credential);
      }
      if (is_string($stderr) && is_file($stderr)) {
        unlink($stderr);
      }
      throw new DbtngException('Unable to create protected native restore process files.');
    }
    $oldUmask = umask(0077);
    try {
      if (file_put_contents($credential, $this->defaultsFile($options), LOCK_EX) === FALSE) {
        throw new DbtngException('Unable to write protected native restore credentials.');
      }
      chmod($credential, 0600);
      $process = proc_open(
        [$binary, '--defaults-extra-file=' . $credential, '--database=' . $database],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', $stderr, 'wb']],
        $pipes,
        NULL,
        NULL,
        ['bypass_shell' => TRUE],
      );
      if (!is_resource($process)) {
        throw new DbtngException('Unable to start the native MySQL restore client.');
      }
      $input = $request->format->compressed() ? gzopen($path, 'rb') : fopen($path, 'rb');
      if ($input === FALSE) {
        fclose($pipes[0]);
        proc_terminate($process);
        proc_close($process);
        throw new DbtngException('Unable to read the native SQL restore artifact.');
      }
      try {
        while (!feof($input)) {
          $chunk = $request->format->compressed() ? gzread($input, 1024 * 1024) : fread($input, 1024 * 1024);
          if ($chunk === FALSE) {
            throw new DbtngException('The native SQL restore artifact could not be streamed.');
          }
          if ($chunk === '') {
            break;
          }
          $offset = 0;
          while ($offset < strlen($chunk)) {
            $written = fwrite($pipes[0], substr($chunk, $offset));
            if ($written === FALSE || $written === 0) {
              throw new DbtngException('The native MySQL restore client stopped accepting input.');
            }
            $offset += $written;
          }
        }
        if ($request->format->compressed()) {
          $gzipValid = gzclose($input);
          $input = FALSE;
          if (!$gzipValid) {
            throw new DbtngException('The compressed SQL restore artifact is corrupt.');
          }
        }
      }
      finally {
        if ($input !== FALSE) {
          $request->format->compressed() ? gzclose($input) : fclose($input);
        }
        fclose($pipes[0]);
      }
      $exitCode = proc_close($process);
      if ($exitCode !== 0) {
        throw new DbtngException(sprintf('The native MySQL restore client failed with exit code %d; stderr was withheld to protect connection details.', $exitCode));
      }
    }
    catch (\Throwable $exception) {
      if ($exception instanceof DbtngException) {
        throw $exception;
      }
      throw new DbtngException('Native MySQL restore failed; the standby may be partial and was not marked initialized.', 0, $exception);
    }
    finally {
      umask($oldUmask);
      if (is_file($credential)) {
        unlink($credential);
      }
      if (is_file($stderr)) {
        unlink($stderr);
      }
    }
  }

  private function validatePrivateArtifact(string $path, NativeBackupFormat $format): string {
    if (is_link($path) || !is_file($path) || ($permissions = fileperms($path)) === FALSE || ($permissions & 0077) !== 0) {
      throw new DbtngException('Restore artifact must be a regular owner-private file.');
    }
    $real = realpath($path);
    if ($real === FALSE || (defined('DRUPAL_ROOT') && str_starts_with($real, rtrim((string) realpath(DRUPAL_ROOT), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
      throw new DbtngException('Restore artifact must be outside the Drupal webroot.');
    }
    $handle = fopen($real, 'rb');
    if ($handle === FALSE) {
      throw new DbtngException('Restore artifact cannot be opened.');
    }
    $header = fread($handle, 32);
    fclose($handle);
    $isGzip = is_string($header) && str_starts_with($header, "\x1f\x8b");
    if ($format->compressed() !== $isGzip || str_starts_with((string) $header, "SQLite format 3\0")) {
      throw new DbtngException('Restore artifact contents do not match a MySQL SQL backup format.');
    }
    $name = strtolower($real);
    if ($format === NativeBackupFormat::MysqlSqlGzip && !str_ends_with($name, '.sql.gz')) {
      throw new DbtngException('Compressed MySQL restore artifacts must use the .sql.gz suffix.');
    }
    if ($format === NativeBackupFormat::MysqlSql && !str_ends_with($name, '.sql')) {
      throw new DbtngException('MySQL restore artifacts must use the .sql suffix.');
    }
    return $real;
  }

  /**
   * Creates a protected native-client options file.
   *
   * @param array<string, mixed> $options
   *   Drupal connection options.
   */
  private function defaultsFile(array $options): string {
    $lines = ['[client]'];
    $names = [
      'username' => 'user',
      'password' => 'password',
      'host' => 'host',
      'port' => 'port',
      'unix_socket' => 'socket',
    ];
    foreach ($names as $key => $option) {
      if (isset($options[$key]) && is_scalar($options[$key])) {
        $value = str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '\\r', '\\n'], (string) $options[$key]);
        $lines[] = $option . ' = "' . $value . '"';
      }
    }
    return implode("\n", $lines) . "\n";
  }

  private function client(DatabaseProduct $product): ?string {
    $names = $product === DatabaseProduct::MariaDb ? ['mariadb', 'mysql'] : ['mysql'];
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

}
