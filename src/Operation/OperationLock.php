<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Operation;

use Drupal\Core\Site\Settings;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Exception\OperationLockedException;

/**
 * Serializes database-changing DBTNG operations across CLI processes.
 *
 * The lock inode is deliberately persistent: flock ownership, rather than a
 * stale PID file or file age, determines whether an operation is active.
 */
final class OperationLock {

  public function acquire(string $operation): OperationLockHandle {
    if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $operation) !== 1) {
      throw new \InvalidArgumentException('Invalid DBTNG operation name.');
    }
    $privatePath = Settings::get('file_private_path');
    if (!is_string($privatePath) || $privatePath === '' || is_link($privatePath)) {
      throw new DbtngException('A real private path is required for the DBTNG operation lock.');
    }
    $root = realpath($privatePath);
    if ($root === FALSE || !is_dir($root)) {
      throw new DbtngException('The configured private path is unavailable for the DBTNG operation lock.');
    }
    $directory = $root . DIRECTORY_SEPARATOR . 'dbtng';
    if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory))) {
      throw new DbtngException('Unable to create the private DBTNG operation directory.');
    }
    $realDirectory = realpath($directory);
    if ($realDirectory === FALSE || dirname($realDirectory) !== $root) {
      throw new DbtngException('The DBTNG operation lock directory must be directly under private storage.');
    }
    $mode = fileperms($realDirectory);
    if ($mode === FALSE || ($mode & 0077) !== 0) {
      throw new DbtngException('The DBTNG operation lock directory must be owner-private.');
    }
    $path = $realDirectory . DIRECTORY_SEPARATOR . '.dbtng.lock';
    if (is_link($path)) {
      throw new DbtngException('The DBTNG operation lock must not be a symbolic link.');
    }
    $stream = @fopen($path, 'c+');
    if ($stream === FALSE || !chmod($path, 0600)) {
      if (is_resource($stream)) {
        fclose($stream);
      }
      throw new DbtngException('Unable to open the private DBTNG operation lock.');
    }
    if (!flock($stream, LOCK_EX | LOCK_NB)) {
      rewind($stream);
      $metadata = stream_get_contents($stream, 512);
      fclose($stream);
      $activeOperation = 'unknown';
      $pid = 'unknown';
      if (is_string($metadata)) {
        try {
          $record = json_decode($metadata, TRUE, 4, JSON_THROW_ON_ERROR);
          if (is_array($record)) {
            $activeOperation = (string) ($record['operation'] ?? $activeOperation);
            $pid = (string) ($record['pid'] ?? $pid);
          }
        }
        catch (\JsonException) {
          // A concurrent writer may be between truncate and write.
        }
      }
      throw new OperationLockedException(sprintf('Another DBTNG operation is active (%s, PID %s).', $activeOperation, $pid));
    }

    $record = json_encode([
      'operation' => $operation,
      'pid' => getmypid(),
      'started_at' => gmdate(DATE_ATOM),
      'uuid' => bin2hex(random_bytes(16)),
    ], JSON_THROW_ON_ERROR);
    rewind($stream);
    if (!ftruncate($stream, 0) || fwrite($stream, $record . "\n") === FALSE || !fflush($stream)) {
      flock($stream, LOCK_UN);
      fclose($stream);
      throw new DbtngException('Unable to record DBTNG operation lock metadata.');
    }
    return new OperationLockHandle($stream);
  }

}
