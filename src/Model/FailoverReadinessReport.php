<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Secret-free read-only evidence for a future controlled authority switch.
 */
final readonly class FailoverReadinessReport {

  /**
   * Creates a secret-free failover-readiness evidence report.
   *
   * @param list<FailoverBlocker> $blockers
   *   Typed blockers preventing controlled promotion.
   */
  public function __construct(
    public FailoverReadinessStatus $status,
    public string $primaryEngine,
    public string $standbyEngine,
    public ReplicationProfile $profile,
    public bool $captureHealthy,
    public int $pendingEvents,
    public ReconciliationStatus $reconciliationStatus,
    public bool $integrityPass,
    public bool $manifestPass,
    public ?string $generationId,
    public array $blockers,
  ) {
    if ($pendingEvents < 0) {
      throw new \InvalidArgumentException('Pending failover event count cannot be negative.');
    }
    if (($status === FailoverReadinessStatus::Ready) !== ($blockers === [])) {
      throw new \InvalidArgumentException('Failover readiness status must agree with its blocker set.');
    }
  }

  public function ready(): bool {
    return $this->status === FailoverReadinessStatus::Ready;
  }

  /**
   * Returns machine-readable secret-free readiness evidence.
   *
   * @return array<string, bool|int|string|array<int, array{code: string, message: string}>|null>
   *   Machine-readable readiness evidence.
   */
  public function toArray(): array {
    return [
      'status' => $this->status->value,
      'primary_engine' => $this->primaryEngine,
      'standby_engine' => $this->standbyEngine,
      'profile' => $this->profile->value,
      'capture_healthy' => $this->captureHealthy,
      'pending_events' => $this->pendingEvents,
      'reconciliation_status' => $this->reconciliationStatus->value,
      'integrity_pass' => $this->integrityPass,
      'manifest_pass' => $this->manifestPass,
      'generation_id' => $this->generationId,
      'external_write_fence_required' => TRUE,
      'continuous_worker_stop_required' => TRUE,
      'automatic_promotion' => FALSE,
      'blockers' => array_map(
        static fn (FailoverBlocker $blocker): array => $blocker->toArray(),
        $this->blockers,
      ),
    ];
  }

}
