<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Support;

use Drupal\dbtng_migrator\Contract\ClockInterface;

/**
 * Mutable deterministic UTC clock for worker tests.
 */
final class MutableClock implements ClockInterface {

  public function __construct(private \DateTimeImmutable $now) {}

  public function now(): \DateTimeImmutable {
    return $this->now;
  }

  public function advance(int $seconds): void {
    $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
  }

}
