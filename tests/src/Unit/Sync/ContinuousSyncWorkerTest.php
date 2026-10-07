<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Sync;

use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Contract\ChangeCaptureInterface;
use Drupal\dbtng_migrator\Contract\DatabaseTopologyResolverInterface;
use Drupal\dbtng_migrator\Contract\SyncEngineInterface;
use Drupal\dbtng_migrator\Exception\SyncTransientException;
use Drupal\dbtng_migrator\Model\ChangeBacklogStatus;
use Drupal\dbtng_migrator\Model\ChangeCaptureStatus;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseProduct;
use Drupal\dbtng_migrator\Model\DatabaseRole;
use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\ResolvedDatabase;
use Drupal\dbtng_migrator\Model\ResolvedTopology;
use Drupal\dbtng_migrator\Model\SyncBatchResult;
use Drupal\dbtng_migrator\Model\SyncResultStatus;
use Drupal\dbtng_migrator\Model\SyncWorkerConfiguration;
use Drupal\dbtng_migrator\Model\SyncWorkerState;
use Drupal\dbtng_migrator\Sync\ContinuousSyncWorker;
use Drupal\dbtng_migrator\Sync\PrivateRuntimeDirectory;
use Drupal\dbtng_migrator\Sync\SyncBackoffPolicy;
use Drupal\dbtng_migrator\Sync\SyncWorkerLock;
use Drupal\dbtng_migrator\Sync\SyncWorkerStateStore;
use Drupal\Tests\dbtng_migrator\Unit\Support\MutableClock;
use Drupal\Tests\dbtng_migrator\Unit\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the continuous worker around the existing sync-once primitive.
 */
final class ContinuousSyncWorkerTest extends TestCase {

  private string $privatePath;

  protected function setUp(): void {
    parent::setUp();
    $this->privatePath = sys_get_temp_dir() . '/dbtng-continuous-' . bin2hex(random_bytes(8));
    self::assertTrue(mkdir($this->privatePath, 0700));
  }

  protected function tearDown(): void {
    $this->remove($this->privatePath);
    parent::tearDown();
  }

  public function testIdleWorkerSleepsWithoutCallingSyncOnce(): void {
    $sync = $this->createMock(SyncEngineInterface::class);
    $sync->expects(self::never())->method('syncOnce');
    $capture = $this->captureMock([new ChangeBacklogStatus(0), new ChangeBacklogStatus(0)]);
    [$worker, $store, $sleeper] = $this->worker($sync, $capture);

    $worker->run(NULL, 1);

    self::assertSame([1], $sleeper->delays);
    self::assertSame(SyncWorkerState::Stopped, $store->read()?->state);
  }

  public function testBacklogDrainsWithoutSleepingBetweenMorePendingBatches(): void {
    $sync = $this->createMock(SyncEngineInterface::class);
    $sync->expects(self::exactly(3))
      ->method('syncOnce')
      ->with(500)
      ->willReturnOnConsecutiveCalls(
        $this->batchResult(SyncResultStatus::MorePending, 700),
        $this->batchResult(SyncResultStatus::MorePending, 200),
        $this->batchResult(SyncResultStatus::CaughtUp, 0),
      );
    $capture = $this->captureMock([
      new ChangeBacklogStatus(1200, 1, 1200, 30),
      new ChangeBacklogStatus(700, 501, 1200, 20),
      new ChangeBacklogStatus(200, 1001, 1200, 10),
      new ChangeBacklogStatus(0),
      new ChangeBacklogStatus(0),
    ]);
    [$worker, , $sleeper] = $this->worker($sync, $capture);

    $worker->run(NULL, 3);

    self::assertSame([1], $sleeper->delays, 'MORE_PENDING batches must drain immediately without idle sleeps.');
  }

  public function testTransientFailuresUseCappedProgressiveBackoff(): void {
    $sync = $this->createMock(SyncEngineInterface::class);
    $sync->expects(self::exactly(3))
      ->method('syncOnce')
      ->willThrowException(new SyncTransientException('standby unavailable'));
    $capture = $this->captureMock([
      new ChangeBacklogStatus(3, 1, 3, 1),
      new ChangeBacklogStatus(3, 1, 3, 2),
      new ChangeBacklogStatus(3, 1, 3, 4),
      new ChangeBacklogStatus(3, 1, 3, 8),
    ]);
    [$worker, $store, $sleeper] = $this->worker($sync, $capture);

    $worker->run(NULL, 3);

    self::assertSame([1, 2, 4], $sleeper->delays);
    self::assertSame(SyncWorkerState::Stopped, $store->read()?->state);
  }

  public function testBackoffResetsAfterSuccessfulBatch(): void {
    $sync = $this->createMock(SyncEngineInterface::class);
    $attempt = 0;
    $sync->expects(self::exactly(3))
      ->method('syncOnce')
      ->willReturnCallback(function () use (&$attempt): SyncBatchResult {
        $attempt++;
        if ($attempt === 1 || $attempt === 3) {
          throw new SyncTransientException('standby unavailable');
        }
        return $this->batchResult(SyncResultStatus::CaughtUp, 0);
      });

    $capture = $this->captureMock([
      new ChangeBacklogStatus(2, 1, 2, 1),
      new ChangeBacklogStatus(2, 1, 2, 2),
      new ChangeBacklogStatus(0),
      new ChangeBacklogStatus(1, 3, 3, 1),
      new ChangeBacklogStatus(1, 3, 3, 2),
    ]);
    [$worker, , $sleeper] = $this->worker($sync, $capture);

    $worker->run(NULL, 3);

    self::assertSame([1, 1, 1], $sleeper->delays, 'A successful batch must reset transient backoff before the next failure.');
  }

  public function testStopRequestedBeforeLoopDoesNotStartBatch(): void {
    $sync = $this->createMock(SyncEngineInterface::class);
    $sync->expects(self::never())->method('syncOnce');
    $capture = $this->captureMock([new ChangeBacklogStatus(0)]);
    [$worker, $store, $sleeper] = $this->worker($sync, $capture);

    $worker->requestStop();
    $worker->run();

    self::assertSame([], $sleeper->delays);
    self::assertSame(SyncWorkerState::Stopped, $store->read()?->state);
  }

  /**
   * Creates a healthy capture mock with deterministic backlog samples.
   *
   * @param list<ChangeBacklogStatus> $backlogs
   *   Backlog values returned in call order.
   */
  private function captureMock(array $backlogs): ChangeCaptureInterface {
    $capture = $this->createMock(ChangeCaptureInterface::class);
    $capture->method('status')->willReturn(new ChangeCaptureStatus(
      DatabaseEngine::MysqlFamily,
      TRUE,
      TRUE,
      2,
      6,
      6,
      $backlogs[0]->pendingEvents ?? 0,
      0,
    ));
    $capture->method('backlog')->willReturnOnConsecutiveCalls(...$backlogs);
    return $capture;
  }

  /**
   * Builds a worker with real private state/locking and deterministic time.
   *
   * @return array{ContinuousSyncWorker, SyncWorkerStateStore, RecordingSleeper}
   *   Worker, state store and recording sleeper.
   */
  private function worker(SyncEngineInterface $sync, ChangeCaptureInterface $capture): array {
    $clock = new MutableClock(new \DateTimeImmutable('2026-10-07T12:00:00+00:00'));
    $sleeper = new RecordingSleeper($clock);
    $runtime = new PrivateRuntimeDirectory($this->privatePath);
    $store = new SyncWorkerStateStore($runtime);
    $resolver = $this->createMock(DatabaseTopologyResolverInterface::class);
    $resolver->method('resolve')->willReturn($this->topology());
    return [
      new ContinuousSyncWorker(
        $sync,
        $capture,
        $resolver,
        new SyncWorkerConfiguration(),
        new SyncBackoffPolicy(),
        $store,
        new SyncWorkerLock($runtime),
        $clock,
        $sleeper,
        new NullLogger(),
      ),
      $store,
      $sleeper,
    ];
  }

  private function topology(): ResolvedTopology {
    $connection = $this->createMock(Connection::class);
    $topology = new DatabaseTopology(DatabaseEngine::MysqlFamily, DatabaseEngine::Sqlite);
    return new ResolvedTopology(
      $topology,
      new ResolvedDatabase(DatabaseRole::Primary, 'default', $connection, DatabaseEngine::MysqlFamily, DatabaseProduct::MariaDb, '11.8', 'mysql:test'),
      new ResolvedDatabase(DatabaseRole::Standby, 'dbtng_standby', $connection, DatabaseEngine::Sqlite, DatabaseProduct::Sqlite, '3.46', 'sqlite:test', '/tmp/test.sqlite'),
      ReplicationProfile::Full,
    );
  }

  private function batchResult(SyncResultStatus $status, int $pending): SyncBatchResult {
    return new SyncBatchResult(500, 50, 0, 40, 10, 0, 0, 500, $pending, 10, 1024, $status, 'MariaDB', 'SQLite', 'full');
  }

  private function remove(string $path): void {
    if (!is_dir($path)) {
      return;
    }
    foreach (scandir($path) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $child = $path . DIRECTORY_SEPARATOR . $entry;
      if (is_dir($child) && !is_link($child)) {
        $this->remove($child);
      }
      else {
        @unlink($child);
      }
    }
    @rmdir($path);
  }

}
