<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\SyncWorkerSnapshot;

/**
 * Atomically persists secret-free worker status under private storage.
 */
final class SyncWorkerStateStore {

  public function __construct(private readonly PrivateRuntimeDirectory $runtimeDirectory) {}

  public function write(SyncWorkerSnapshot $snapshot): void {
    $directory = $this->runtimeDirectory->path();
    $path = $directory . DIRECTORY_SEPARATOR . 'sync-status.json';
    if (is_link($path)) {
      throw new DbtngException('The DBTNG worker state file must not be a symbolic link.');
    }
    $encoded = json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $temporary = tempnam($directory, '.sync-status-');
    if ($temporary === FALSE) {
      throw new DbtngException('Unable to create a temporary worker-state file.');
    }
    $stream = @fopen($temporary, 'wb');
    if ($stream === FALSE || !chmod($temporary, 0600)) {
      if (is_resource($stream)) {
        fclose($stream);
      }
      @unlink($temporary);
      throw new DbtngException('Unable to protect the temporary worker-state file.');
    }
    try {
      if (fwrite($stream, $encoded) !== strlen($encoded) || !fflush($stream)) {
        throw new DbtngException('Unable to write DBTNG worker state.');
      }
      if (function_exists('fsync') && !fsync($stream)) {
        throw new DbtngException('Unable to durably flush DBTNG worker state.');
      }
      fclose($stream);
      $stream = NULL;
      if (!rename($temporary, $path) || !chmod($path, 0600)) {
        throw new DbtngException('Unable to atomically publish DBTNG worker state.');
      }
    }
    finally {
      if (is_resource($stream)) {
        fclose($stream);
      }
      if (is_file($temporary)) {
        unlink($temporary);
      }
    }
  }

  public function read(): ?SyncWorkerSnapshot {
    $path = $this->runtimeDirectory->path() . DIRECTORY_SEPARATOR . 'sync-status.json';
    if (!is_file($path) || is_link($path)) {
      return NULL;
    }
    $json = file_get_contents($path);
    if (!is_string($json)) {
      return NULL;
    }
    try {
      $data = json_decode($json, TRUE, 32, JSON_THROW_ON_ERROR);
      return is_array($data) ? SyncWorkerSnapshot::fromArray($data) : NULL;
    }
    catch (\JsonException|\InvalidArgumentException|\ValueError) {
      return NULL;
    }
  }

}
