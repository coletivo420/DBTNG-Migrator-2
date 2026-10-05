<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Describes current durable capture/application positions.
 */
final readonly class SyncStatus {

  public function __construct(
    public int $capturedSequence,
    public int $appliedSequence,
    public int $pendingChanges,
    public bool $standbyHealthy,
  ) {}

  public function lag(): int {
    return max(0, $this->capturedSequence - $this->appliedSequence);
  }

}
