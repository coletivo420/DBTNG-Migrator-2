<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Operation;

/**
 * Holds an operation flock until explicitly released or the process exits. */
final class OperationLockHandle {

  /**
   * Active locked file descriptor.
   *
   * @var resource|null
   */
  private $stream;

  /**
   * Holds the locked file descriptor.
   *
   * @param resource $stream
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
