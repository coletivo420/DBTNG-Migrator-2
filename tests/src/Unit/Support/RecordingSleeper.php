<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Support;

use Drupal\dbtng_migrator\Contract\SleeperInterface;

/**
 * Records sleeps and advances a deterministic test clock.
 */
final class RecordingSleeper implements SleeperInterface {

  /**
   * Recorded sleep intervals.
   *
   * @var list<int>
   */
  public array $delays = [];

  public function __construct(private readonly MutableClock $clock) {}

  public function sleep(int $seconds): void {
    $this->delays[] = $seconds;
    $this->clock->advance($seconds);
  }

}
