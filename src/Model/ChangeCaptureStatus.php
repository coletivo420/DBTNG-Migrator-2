<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Health and backlog summary for the selected primary's capture layer.
 */
final readonly class ChangeCaptureStatus {

  /**
   * @param list<string> $warnings
   *   Non-fatal correctness/coverage warnings.
   */
  public function __construct(
    public DatabaseEngine $engine,
    public bool $installed,
    public bool $healthy,
    public int $trackedTables,
    public int $expectedTriggers,
    public int $installedTriggers,
    public int $pendingEvents,
    public int $tableDirtyTables,
    public ?int $oldestEventId = NULL,
    public ?int $newestEventId = NULL,
    public array $warnings = [],
  ) {}

}
