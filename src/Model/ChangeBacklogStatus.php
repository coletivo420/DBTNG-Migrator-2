<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Lightweight durable-capture backlog metrics.
 */
final readonly class ChangeBacklogStatus {

  public function __construct(
    public int $pendingEvents,
    public ?int $oldestEventId = NULL,
    public ?int $newestEventId = NULL,
    public ?int $oldestPendingAgeSeconds = NULL,
  ) {
    if ($pendingEvents < 0 || ($oldestPendingAgeSeconds !== NULL && $oldestPendingAgeSeconds < 0)) {
      throw new \InvalidArgumentException('Capture backlog metrics cannot be negative.');
    }
  }

}
