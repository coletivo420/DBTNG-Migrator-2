<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Secret-free persistent worker heartbeat and operational state.
 */
final readonly class SyncWorkerSnapshot {

  public const FORMAT_VERSION = 1;

  public function __construct(
    public int $pid,
    public SyncWorkerState $state,
    public string $startedAt,
    public string $heartbeatAt,
    public string $primaryEngine,
    public string $standbyEngine,
    public string $profile,
    public int $pendingEvents = 0,
    public ?int $oldestPendingAgeSeconds = NULL,
    public ?string $lastSuccessAt = NULL,
    public ?string $lastBatchUuid = NULL,
    public ?string $lastBatchResult = NULL,
    public int $consecutiveFailures = 0,
    public int $currentBackoffSeconds = 0,
    public ?string $lastErrorClass = NULL,
    public ?string $lastErrorAt = NULL,
  ) {
    if ($pid < 1 || $pendingEvents < 0 || $consecutiveFailures < 0 || $currentBackoffSeconds < 0) {
      throw new \InvalidArgumentException('Worker state numeric fields must be non-negative and PID must be positive.');
    }
  }

  /**
   * @return array<string, int|string|null>
   *   JSON-safe worker state.
   */
  public function toArray(): array {
    return [
      'format_version' => self::FORMAT_VERSION,
      'pid' => $this->pid,
      'state' => $this->state->value,
      'started_at' => $this->startedAt,
      'heartbeat_at' => $this->heartbeatAt,
      'last_success_at' => $this->lastSuccessAt,
      'last_batch_uuid' => $this->lastBatchUuid,
      'last_batch_result' => $this->lastBatchResult,
      'pending_events' => $this->pendingEvents,
      'oldest_pending_age_seconds' => $this->oldestPendingAgeSeconds,
      'consecutive_failures' => $this->consecutiveFailures,
      'current_backoff_seconds' => $this->currentBackoffSeconds,
      'last_error_class' => $this->lastErrorClass,
      'last_error_at' => $this->lastErrorAt,
      'primary_engine' => $this->primaryEngine,
      'standby_engine' => $this->standbyEngine,
      'profile' => $this->profile,
    ];
  }

  /**
   * Rehydrates validated state from a private JSON document.
   *
   * @param array<string, mixed> $data
   *   Decoded JSON.
   */
  public static function fromArray(array $data): self {
    if (($data['format_version'] ?? NULL) !== self::FORMAT_VERSION) {
      throw new \InvalidArgumentException('Unsupported worker-state format version.');
    }
    return new self(
      (int) ($data['pid'] ?? 0),
      SyncWorkerState::from((string) ($data['state'] ?? '')),
      (string) ($data['started_at'] ?? ''),
      (string) ($data['heartbeat_at'] ?? ''),
      (string) ($data['primary_engine'] ?? 'unknown'),
      (string) ($data['standby_engine'] ?? 'unknown'),
      (string) ($data['profile'] ?? 'unknown'),
      (int) ($data['pending_events'] ?? 0),
      isset($data['oldest_pending_age_seconds']) ? (int) $data['oldest_pending_age_seconds'] : NULL,
      isset($data['last_success_at']) ? (string) $data['last_success_at'] : NULL,
      isset($data['last_batch_uuid']) ? (string) $data['last_batch_uuid'] : NULL,
      isset($data['last_batch_result']) ? (string) $data['last_batch_result'] : NULL,
      (int) ($data['consecutive_failures'] ?? 0),
      (int) ($data['current_backoff_seconds'] ?? 0),
      isset($data['last_error_class']) ? (string) $data['last_error_class'] : NULL,
      isset($data['last_error_at']) ? (string) $data['last_error_at'] : NULL,
    );
  }

}
