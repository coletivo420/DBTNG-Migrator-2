<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Metrics for one sync-once batch; never contains credentials or row data. */
final readonly class SyncBatchResult {

  public function __construct(
    public int $capturedEvents,
    public int $dirtyIdentities,
    public int $tableDirty,
    public int $upserts,
    public int $deletes,
    public int $policyNoops,
    public int $tableReconciliations,
    public int $acknowledgedEvents,
    public int $pendingAfter,
    public int $durationMilliseconds,
    public int $peakMemoryBytes,
    public SyncResultStatus $result,
    public string $primaryEngine,
    public string $standbyEngine,
    public string $profile,
  ) {}

}
