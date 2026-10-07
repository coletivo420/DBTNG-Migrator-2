<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Exception\WorkerAlreadyRunningException;

/**
 * Prevents multiple long-lived watch workers without blocking batch locks.
 */
final class SyncWorkerLock {

  public function __construct(private readonly PrivateRuntimeDirectory $runtimeDirectory) {}

  public function acquire(): SyncWorkerLockHandle {
    $path = $this->runtimeDirectory->path() . DIRECTORY_SEPARATOR . 'sync-worker.lock';
    if (is_link($path)) {
      throw new DbtngException('The DBTNG worker lock must not be a symbolic link.');
    }
    $stream = @fopen($path, 'c+');
    if ($stream === FALSE || !chmod($path, 0600)) {
      if (is_resource($stream)) {
        fclose($stream);
      }
      throw new DbtngException('Unable to open the private DBTNG worker lock.');
    }
    if (!flock($stream, LOCK_EX | LOCK_NB)) {
      fclose($stream);
      throw new WorkerAlreadyRunningException('Another DBTNG continuous sync worker is already active.');
    }
    $metadata = json_encode([
      'pid' => getmypid(),
      'started_at' => gmdate(DATE_ATOM),
    ], JSON_THROW_ON_ERROR);
    rewind($stream);
    if (!ftruncate($stream, 0) || fwrite($stream, $metadata . "\n") === FALSE || !fflush($stream)) {
      flock($stream, LOCK_UN);
      fclose($stream);
      throw new DbtngException('Unable to record DBTNG worker lock metadata.');
    }
    return new SyncWorkerLockHandle($stream);
  }

}
