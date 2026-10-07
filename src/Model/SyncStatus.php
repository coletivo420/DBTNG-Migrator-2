<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Describes observable synchronization backlog without assuming commit order.
 */
final readonly class SyncStatus {

  public function __construct(
    public int $pendingChanges,
    public bool $standbyHealthy,
    public ?int $oldestEventId = NULL,
    public ?int $newestEventId = NULL,
    public ?int $oldestPendingAgeSeconds = NULL,
  ) {
    if ($pendingChanges < 0) {
      throw new \InvalidArgumentException('Pending change count cannot be negative.');
    }
  }

  public function hasLag(): bool {
    return $this->pendingChanges > 0;
  }

}
