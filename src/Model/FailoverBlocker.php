<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * One typed reason why controlled promotion is not data-plane ready.
 */
final readonly class FailoverBlocker {

  public function __construct(
    public FailoverBlockerCode $code,
    public string $message,
  ) {
    if ($message === '') {
      throw new \InvalidArgumentException('Failover blocker message cannot be empty.');
    }
  }

  /**
   * @return array{code: string, message: string}
   *   Machine-readable blocker.
   */
  public function toArray(): array {
    return [
      'code' => $this->code->value,
      'message' => $this->message,
    ];
  }

}
