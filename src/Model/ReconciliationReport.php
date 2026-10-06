<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Detailed, profile-aware read-only reconciliation report. */
final readonly class ReconciliationReport {

  /**
   * Lists per-table comparisons and issues found by the read-only analysis.
   *
   * @param list<TableReconciliationResult> $tables
   * @param list<ReconciliationIssue> $issues
   */
  public function __construct(
    public ReconciliationStatus $status,
    public ReplicationProfile $profile,
    public string $primaryEngine,
    public string $standbyEngine,
    public array $tables,
    public array $issues,
    public bool $integrityPass,
    public bool $manifestPass,
    public string $schemaFingerprint,
  ) {}

  public function matches(): bool {
    return $this->status === ReconciliationStatus::Match;
  }

}
