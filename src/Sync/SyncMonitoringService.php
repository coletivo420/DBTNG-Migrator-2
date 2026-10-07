<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\dbtng_migrator\Contract\ChangeCaptureInterface;
use Drupal\dbtng_migrator\Contract\ClockInterface;
use Drupal\dbtng_migrator\Contract\DatabaseTopologyResolverInterface;
use Drupal\dbtng_migrator\Model\SyncHealth;
use Drupal\dbtng_migrator\Model\SyncMonitoringReport;
use Drupal\dbtng_migrator\Model\SyncWorkerConfiguration;
use Drupal\dbtng_migrator\Model\SyncWorkerState;

/**
 * Classifies continuous-sync health without invoking systemd.
 */
final class SyncMonitoringService {

  public function __construct(
    private readonly ChangeCaptureInterface $capture,
    private readonly DatabaseTopologyResolverInterface $resolver,
    private readonly SyncWorkerConfiguration $configuration,
    private readonly SyncWorkerStateStore $stateStore,
    private readonly ClockInterface $clock,
  ) {}

  public function report(): SyncMonitoringReport {
    $topology = $this->resolver->resolve();
    $capture = $this->capture->status();
    $worker = $this->stateStore->read();
    $heartbeatAge = $worker === NULL ? NULL : max(
      0,
      $this->clock->now()->getTimestamp() - (new \DateTimeImmutable($worker->heartbeatAt))->getTimestamp(),
    );

    $health = match (TRUE) {
      !$capture->healthy => SyncHealth::Blocked,
      $worker === NULL => SyncHealth::Stale,
      $worker->state === SyncWorkerState::Error => SyncHealth::Error,
      $worker->state === SyncWorkerState::Blocked => SyncHealth::Blocked,
      in_array($worker->state, [SyncWorkerState::Stopping, SyncWorkerState::Stopped], TRUE) => SyncHealth::Stale,
      $heartbeatAge !== NULL && $heartbeatAge > $this->configuration->heartbeatStaleSeconds => SyncHealth::Stale,
      $worker->state === SyncWorkerState::Backoff => SyncHealth::Backoff,
      $capture->pendingEvents > 0
        && ($capture->oldestPendingAgeSeconds ?? 0) >= $this->configuration->lagWarningSeconds => SyncHealth::Lagging,
      $capture->pendingEvents > 0 => SyncHealth::CatchingUp,
      default => SyncHealth::Healthy,
    };

    $consecutiveFailures = $worker === NULL ? 0 : $worker->consecutiveFailures;
    $currentBackoff = $worker === NULL ? 0 : $worker->currentBackoffSeconds;

    return new SyncMonitoringReport(
      $health,
      $worker?->state,
      $worker?->pid,
      $heartbeatAge,
      $capture->healthy,
      $capture->pendingEvents,
      $capture->oldestPendingAgeSeconds,
      $topology->primary->label(),
      $topology->standby->label(),
      $topology->profile->value,
      $worker?->lastSuccessAt,
      $consecutiveFailures,
      $currentBackoff,
      $worker?->lastErrorClass,
    );
  }

}
