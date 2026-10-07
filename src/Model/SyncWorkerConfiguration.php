<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Validated continuous-sync worker configuration.
 */
final readonly class SyncWorkerConfiguration {

  public function __construct(
    public int $batchEvents = 500,
    public int $pollSeconds = 1,
    public int $healthCheckSeconds = 30,
    public int $backoffInitialSeconds = 1,
    public int $backoffMaxSeconds = 30,
    public int $blockedRetrySeconds = 60,
    public int $heartbeatStaleSeconds = 120,
    public int $lagWarningSeconds = 60,
  ) {
    if ($batchEvents < 1 || $batchEvents > 5000) {
      throw new \InvalidArgumentException('Sync batch_events must be between 1 and 5000.');
    }
    foreach ([
      'poll_seconds' => $pollSeconds,
      'health_check_seconds' => $healthCheckSeconds,
      'backoff_initial_seconds' => $backoffInitialSeconds,
      'backoff_max_seconds' => $backoffMaxSeconds,
      'blocked_retry_seconds' => $blockedRetrySeconds,
      'heartbeat_stale_seconds' => $heartbeatStaleSeconds,
      'lag_warning_seconds' => $lagWarningSeconds,
    ] as $name => $value) {
      if ($value < 1) {
        throw new \InvalidArgumentException(sprintf('Sync %s must be at least 1 second.', $name));
      }
    }
    if ($backoffMaxSeconds < $backoffInitialSeconds) {
      throw new \InvalidArgumentException('Sync backoff_max_seconds must not be lower than backoff_initial_seconds.');
    }
    if ($heartbeatStaleSeconds <= max($pollSeconds, $backoffMaxSeconds, $blockedRetrySeconds)) {
      throw new \InvalidArgumentException('Sync heartbeat_stale_seconds must exceed all normal sleep/backoff intervals.');
    }
  }

}
