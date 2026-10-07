<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Read-only operator status for capture plus the continuous worker.
 */
final readonly class SyncMonitoringReport {

  public function __construct(
    public SyncHealth $health,
    public ?SyncWorkerState $workerState,
    public ?int $workerPid,
    public ?int $heartbeatAgeSeconds,
    public bool $captureHealthy,
    public int $pendingEvents,
    public ?int $oldestPendingAgeSeconds,
    public string $primaryEngine,
    public string $standbyEngine,
    public string $profile,
    public ?string $lastSuccessAt = NULL,
    public int $consecutiveFailures = 0,
    public int $currentBackoffSeconds = 0,
    public ?string $lastErrorClass = NULL,
  ) {}

  /**
   * @return array<string, bool|int|string|null>
   *   Machine-readable secret-free status.
   */
  public function toArray(): array {
    return [
      'health' => $this->health->value,
      'worker_state' => $this->workerState?->value,
      'worker_pid' => $this->workerPid,
      'heartbeat_age_seconds' => $this->heartbeatAgeSeconds,
      'capture_healthy' => $this->captureHealthy,
      'pending_events' => $this->pendingEvents,
      'oldest_pending_age_seconds' => $this->oldestPendingAgeSeconds,
      'primary_engine' => $this->primaryEngine,
      'standby_engine' => $this->standbyEngine,
      'profile' => $this->profile,
      'last_success_at' => $this->lastSuccessAt,
      'consecutive_failures' => $this->consecutiveFailures,
      'current_backoff_seconds' => $this->currentBackoffSeconds,
      'last_error_class' => $this->lastErrorClass,
    ];
  }

}
