<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\dbtng_migrator\Contract\SleeperInterface;

/**
 * Production worker sleeper.
 */
final class SystemSleeper implements SleeperInterface {

  public function sleep(int $seconds): void {
    if ($seconds < 0) {
      throw new \InvalidArgumentException('Sleep interval cannot be negative.');
    }
    if ($seconds > 0) {
      sleep($seconds);
    }
  }

}
