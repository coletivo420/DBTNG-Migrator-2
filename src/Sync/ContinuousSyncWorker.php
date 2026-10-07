<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\dbtng_migrator\Contract\ChangeCaptureInterface;
use Drupal\dbtng_migrator\Contract\ClockInterface;
use Drupal\dbtng_migrator\Contract\DatabaseTopologyResolverInterface;
use Drupal\dbtng_migrator\Contract\SleeperInterface;
use Drupal\dbtng_migrator\Contract\SyncEngineInterface;
use Drupal\dbtng_migrator\Exception\OperationLockedException;
use Drupal\dbtng_migrator\Exception\SyncBlockedException;
use Drupal\dbtng_migrator\Exception\SyncTransientException;
use Drupal\dbtng_migrator\Model\ChangeBacklogStatus;
use Drupal\dbtng_migrator\Model\SyncResultStatus;
use Drupal\dbtng_migrator\Model\SyncWorkerSnapshot;
use Drupal\dbtng_migrator\Model\SyncWorkerState;
use Psr\Log\LoggerInterface;

/**
 * Repeats the proven sync-once primitive with bounded retry and heartbeat.
 */
final class ContinuousSyncWorker {

  private bool $stopRequested = FALSE;

  public function __construct(
    private readonly SyncEngineInterface $sync,
    private readonly ChangeCaptureInterface $capture,
    private readonly DatabaseTopologyResolverInterface $resolver,
    private readonly SyncWorkerConfigurationFactory $configurationFactory,
    private readonly SyncBackoffPolicy $backoff,
    private readonly SyncWorkerStateStore $stateStore,
    private readonly SyncWorkerLock $workerLock,
    private readonly ClockInterface $clock,
    private readonly SleeperInterface $sleeper,
    private readonly LoggerInterface $logger,
  ) {}

  public function requestStop(): void {
    $this->stopRequested = TRUE;
  }

  /**
   * Runs until signalled; maxCycles exists only for deterministic unit tests.
   */
  public function run(?int $batchLimitOverride = NULL, ?int $maxCycles = NULL): void {
    if ($maxCycles !== NULL && $maxCycles < 1) {
      throw new \InvalidArgumentException('Worker maxCycles must be positive when supplied.');
    }
    $configuration = $this->configurationFactory->create();
    $batchLimit = $batchLimitOverride ?? $configuration->batchEvents;
    if ($batchLimit < 1 || $batchLimit > 5000) {
      throw new \InvalidArgumentException('Worker batch limit must be between 1 and 5000.');
    }

    $lock = $this->workerLock->acquire();
    $topology = $this->resolver->resolve();
    $startedAt = $this->isoNow();
    $lastSuccessAt = NULL;
    $lastBatchUuid = NULL;
    $lastBatchResult = NULL;
    $consecutiveFailures = 0;
    $cycles = 0;
    $nextHealthCheck = 0;
    $lastLoggedState = NULL;

    try {
      $this->persist(
        SyncWorkerState::Starting,
        $startedAt,
        $topology->primary->label(),
        $topology->standby->label(),
        $topology->profile->value,
      );
      $this->logTransition($lastLoggedState, SyncWorkerState::Starting);

      while (!$this->stopRequested && ($maxCycles === NULL || $cycles < $maxCycles)) {
        $cycles++;
        $backlog = new ChangeBacklogStatus(0);
        try {
          $nowTimestamp = $this->clock->now()->getTimestamp();
          if ($nowTimestamp >= $nextHealthCheck) {
            $health = $this->capture->status();
            if (!$health->healthy) {
              throw new SyncBlockedException('Continuous sync blocked: primary capture is unhealthy.');
            }
            $nextHealthCheck = $nowTimestamp + $configuration->healthCheckSeconds;
          }

          $backlog = $this->capture->backlog();
          if ($backlog->pendingEvents === 0) {
            $consecutiveFailures = 0;
            $this->persist(
              SyncWorkerState::Idle,
              $startedAt,
              $topology->primary->label(),
              $topology->standby->label(),
              $topology->profile->value,
              $backlog,
              $lastSuccessAt,
              $lastBatchUuid,
              $lastBatchResult,
            );
            $this->logTransition($lastLoggedState, SyncWorkerState::Idle);
            $this->sleeper->sleep($configuration->pollSeconds);
            continue;
          }

          $this->persist(
            SyncWorkerState::Draining,
            $startedAt,
            $topology->primary->label(),
            $topology->standby->label(),
            $topology->profile->value,
            $backlog,
            $lastSuccessAt,
            $lastBatchUuid,
            $lastBatchResult,
          );
          $this->logTransition($lastLoggedState, SyncWorkerState::Draining);
          $result = $this->sync->syncOnce($batchLimit);
          $consecutiveFailures = 0;
          $lastSuccessAt = $this->isoNow();
          $lastBatchUuid = bin2hex(random_bytes(16));
          $lastBatchResult = $result->result->value;
          $this->logger->info('DBTNG sync batch applied: {events} events, {dirty} dirty identities, {ack} acknowledged, {pending} pending, {duration} ms.', [
            'events' => $result->capturedEvents,
            'dirty' => $result->dirtyIdentities,
            'ack' => $result->acknowledgedEvents,
            'pending' => $result->pendingAfter,
            'duration' => $result->durationMilliseconds,
          ]);

          if ($result->result === SyncResultStatus::MorePending) {
            continue;
          }
          $backlog = $this->capture->backlog();
          $this->persist(
            SyncWorkerState::Idle,
            $startedAt,
            $topology->primary->label(),
            $topology->standby->label(),
            $topology->profile->value,
            $backlog,
            $lastSuccessAt,
            $lastBatchUuid,
            $lastBatchResult,
          );
          $this->logTransition($lastLoggedState, SyncWorkerState::Idle);
          $this->sleeper->sleep($configuration->pollSeconds);
        }
        catch (OperationLockedException|SyncTransientException $exception) {
          $consecutiveFailures++;
          $delay = $this->backoff->delay($consecutiveFailures, $configuration);
          $this->persist(
            SyncWorkerState::Backoff,
            $startedAt,
            $topology->primary->label(),
            $topology->standby->label(),
            $topology->profile->value,
            $backlog,
            $lastSuccessAt,
            $lastBatchUuid,
            $lastBatchResult,
            $consecutiveFailures,
            $delay,
            $exception,
          );
          $this->logTransition($lastLoggedState, SyncWorkerState::Backoff);
          $this->sleeper->sleep($delay);
        }
        catch (SyncBlockedException $exception) {
          $consecutiveFailures++;
          $this->persist(
            SyncWorkerState::Blocked,
            $startedAt,
            $topology->primary->label(),
            $topology->standby->label(),
            $topology->profile->value,
            $backlog,
            $lastSuccessAt,
            $lastBatchUuid,
            $lastBatchResult,
            $consecutiveFailures,
            $configuration->blockedRetrySeconds,
            $exception,
          );
          $this->logTransition($lastLoggedState, SyncWorkerState::Blocked);
          $this->sleeper->sleep($configuration->blockedRetrySeconds);
        }
      }

      $this->persist(
        SyncWorkerState::Stopping,
        $startedAt,
        $topology->primary->label(),
        $topology->standby->label(),
        $topology->profile->value,
        $this->capture->backlog(),
        $lastSuccessAt,
        $lastBatchUuid,
        $lastBatchResult,
      );
      $this->logTransition($lastLoggedState, SyncWorkerState::Stopping);
      $this->persist(
        SyncWorkerState::Stopped,
        $startedAt,
        $topology->primary->label(),
        $topology->standby->label(),
        $topology->profile->value,
        $this->capture->backlog(),
        $lastSuccessAt,
        $lastBatchUuid,
        $lastBatchResult,
      );
      $this->logTransition($lastLoggedState, SyncWorkerState::Stopped);
    }
    catch (\Throwable $exception) {
      $this->persist(
        SyncWorkerState::Error,
        $startedAt,
        $topology->primary->label(),
        $topology->standby->label(),
        $topology->profile->value,
        new ChangeBacklogStatus(0),
        $lastSuccessAt,
        $lastBatchUuid,
        $lastBatchResult,
        $consecutiveFailures,
        0,
        $exception,
      );
      $this->logger->error('DBTNG continuous sync worker terminated with {class}.', ['class' => $exception::class]);
      throw $exception;
    }
    finally {
      $lock->release();
    }
  }

  private function persist(
    SyncWorkerState $state,
    string $startedAt,
    string $primary,
    string $standby,
    string $profile,
    ?ChangeBacklogStatus $backlog = NULL,
    ?string $lastSuccessAt = NULL,
    ?string $lastBatchUuid = NULL,
    ?string $lastBatchResult = NULL,
    int $consecutiveFailures = 0,
    int $currentBackoff = 0,
    ?\Throwable $error = NULL,
  ): void {
    $backlog ??= new ChangeBacklogStatus(0);
    $this->stateStore->write(new SyncWorkerSnapshot(
      getmypid() ?: 1,
      $state,
      $startedAt,
      $this->isoNow(),
      $primary,
      $standby,
      $profile,
      $backlog->pendingEvents,
      $backlog->oldestPendingAgeSeconds,
      $lastSuccessAt,
      $lastBatchUuid,
      $lastBatchResult,
      $consecutiveFailures,
      $currentBackoff,
      $error?::class,
      $error === NULL ? NULL : $this->isoNow(),
    ));
  }

  private function isoNow(): string {
    return $this->clock->now()->format(DATE_ATOM);
  }

  private function logTransition(?SyncWorkerState &$previous, SyncWorkerState $current): void {
    if ($previous === $current) {
      return;
    }
    $this->logger->info('DBTNG continuous sync worker state: {state}.', ['state' => $current->value]);
    $previous = $current;
  }

}
