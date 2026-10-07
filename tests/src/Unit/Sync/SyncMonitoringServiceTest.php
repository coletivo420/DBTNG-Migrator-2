<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Sync;

use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Contract\ChangeCaptureInterface;
use Drupal\dbtng_migrator\Contract\DatabaseTopologyResolverInterface;
use Drupal\dbtng_migrator\Model\ChangeCaptureStatus;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseProduct;
use Drupal\dbtng_migrator\Model\DatabaseRole;
use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\ResolvedDatabase;
use Drupal\dbtng_migrator\Model\ResolvedTopology;
use Drupal\dbtng_migrator\Model\SyncHealth;
use Drupal\dbtng_migrator\Model\SyncWorkerConfiguration;
use Drupal\dbtng_migrator\Model\SyncWorkerSnapshot;
use Drupal\dbtng_migrator\Model\SyncWorkerState;
use Drupal\dbtng_migrator\Sync\PrivateRuntimeDirectory;
use Drupal\dbtng_migrator\Sync\SyncMonitoringService;
use Drupal\dbtng_migrator\Sync\SyncWorkerStateStore;
use Drupal\Tests\dbtng_migrator\Unit\Support\MutableClock;
use PHPUnit\Framework\TestCase;

/**
 * Tests operator health classification independent of systemd.
 */
final class SyncMonitoringServiceTest extends TestCase {

  private string $privatePath;

  protected function setUp(): void {
    parent::setUp();
    $this->privatePath = sys_get_temp_dir() . '/dbtng-monitor-' . bin2hex(random_bytes(8));
    self::assertTrue(mkdir($this->privatePath, 0700));
  }

  protected function tearDown(): void {
    $this->remove($this->privatePath);
    parent::tearDown();
  }

  public function testLaggingAndStaleClassification(): void {
    $clock = new MutableClock(new \DateTimeImmutable('2026-10-07T12:10:00+00:00'));
    $store = new SyncWorkerStateStore(new PrivateRuntimeDirectory($this->privatePath));
    $store->write(new SyncWorkerSnapshot(
      321,
      SyncWorkerState::Idle,
      '2026-10-07T12:00:00+00:00',
      '2026-10-07T12:09:59+00:00',
      'MariaDB',
      'SQLite',
      'clean',
    ));
    $capture = $this->createMock(ChangeCaptureInterface::class);
    $capture->method('status')->willReturn(new ChangeCaptureStatus(
      DatabaseEngine::MysqlFamily,
      TRUE,
      TRUE,
      58,
      174,
      174,
      4,
      0,
      10,
      13,
      [],
      90,
    ));
    $monitor = new SyncMonitoringService(
      $capture,
      $this->resolver(),
      new SyncWorkerConfiguration(),
      $store,
      $clock,
    );
    self::assertSame(SyncHealth::Lagging, $monitor->report()->health);

    $clock->advance(121);
    self::assertSame(SyncHealth::Stale, $monitor->report()->health);
  }

  private function resolver(): DatabaseTopologyResolverInterface {
    $connection = $this->createMock(Connection::class);
    $resolver = $this->createMock(DatabaseTopologyResolverInterface::class);
    $resolver->method('resolve')->willReturn(new ResolvedTopology(
      new DatabaseTopology(DatabaseEngine::MysqlFamily, DatabaseEngine::Sqlite),
      new ResolvedDatabase(DatabaseRole::Primary, 'default', $connection, DatabaseEngine::MysqlFamily, DatabaseProduct::MariaDb, '11.8', 'mysql:test'),
      new ResolvedDatabase(DatabaseRole::Standby, 'dbtng_standby', $connection, DatabaseEngine::Sqlite, DatabaseProduct::Sqlite, '3.46', 'sqlite:test'),
      ReplicationProfile::Clean,
    ));
    return $resolver;
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
