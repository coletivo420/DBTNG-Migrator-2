<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\dbtng_migrator\Contract\ClockInterface;

/**
 * Production UTC clock.
 */
final class SystemClock implements ClockInterface {

  public function now(): \DateTimeImmutable {
    return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
  }

}
