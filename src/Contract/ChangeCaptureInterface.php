<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\ChangeCaptureStatus;

/**
 * Manages durable capture on the currently configured primary role.
 */
interface ChangeCaptureInterface {

  public function install(): ChangeCaptureStatus;

  public function uninstall(): void;

  public function isInstalled(): bool;

  public function status(): ChangeCaptureStatus;

  /**
   * Returns currently committed, unacknowledged capture events.
   *
   * @return list<\Drupal\dbtng_migrator\Model\ChangeRecord>
   */
  public function pending(int $limit = 500): array;

  /**
   * Removes exact events only after downstream application is durable.
   *
   * @param list<int> $eventIds
   *   Exact capture event identifiers.
   */
  public function acknowledge(array $eventIds): void;

}
