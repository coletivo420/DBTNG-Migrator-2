<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

/**
 * Holds the continuous-worker lifetime flock.
 */
final class SyncWorkerLockHandle {

  /**
   * Active worker-lock file descriptor.
   *
   * @var resource|null
   */
  private $stream;

  /**
   * Holds the locked worker file descriptor.
   *
   * @param resource $stream
   *   Locked worker file descriptor.
   */
  public function __construct($stream) {
    $this->stream = $stream;
  }

  public function release(): void {
    if (is_resource($this->stream)) {
      flock($this->stream, LOCK_UN);
      fclose($this->stream);
      $this->stream = NULL;
    }
  }

  public function __destruct() {
    $this->release();
  }

}
