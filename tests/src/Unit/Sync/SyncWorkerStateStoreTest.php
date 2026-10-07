<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Sync;

use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Exception\WorkerAlreadyRunningException;
use Drupal\dbtng_migrator\Model\SyncWorkerSnapshot;
use Drupal\dbtng_migrator\Model\SyncWorkerState;
use Drupal\dbtng_migrator\Sync\PrivateRuntimeDirectory;
use Drupal\dbtng_migrator\Sync\SyncWorkerLock;
use Drupal\dbtng_migrator\Sync\SyncWorkerStateStore;
use PHPUnit\Framework\TestCase;

/**
 * Tests private worker state and singleton lifetime locking.
 */
final class SyncWorkerStateStoreTest extends TestCase {

  private string $privatePath;

  protected function setUp(): void {
    parent::setUp();
    $this->privatePath = sys_get_temp_dir() . '/dbtng-worker-' . bin2hex(random_bytes(8));
    self::assertTrue(mkdir($this->privatePath, 0700));
  }

  protected function tearDown(): void {
    $this->remove($this->privatePath);
    parent::tearDown();
  }

  public function testAtomicRoundTripAndPermissions(): void {
    $runtime = new PrivateRuntimeDirectory($this->privatePath);
    $store = new SyncWorkerStateStore($runtime);
    $snapshot = new SyncWorkerSnapshot(
      123,
      SyncWorkerState::Idle,
      '2026-10-07T12:00:00+00:00',
      '2026-10-07T12:00:05+00:00',
      'MariaDB',
      'SQLite',
      'clean',
      7,
      4,
    );
    $store->write($snapshot);

    $read = $store->read();
    self::assertNotNull($read);
    self::assertSame(SyncWorkerState::Idle, $read->state);
    self::assertSame(7, $read->pendingEvents);
    self::assertSame(4, $read->oldestPendingAgeSeconds);

    $directory = $runtime->path();
    self::assertSame(0, fileperms($directory) & 0077);
    self::assertSame(0, fileperms($directory . '/sync-status.json') & 0077);
  }

  public function testCorruptStateIsIgnored(): void {
    $runtime = new PrivateRuntimeDirectory($this->privatePath);
    $store = new SyncWorkerStateStore($runtime);
    file_put_contents($runtime->path() . '/sync-status.json', '{broken');
    self::assertNull($store->read());
  }

  public function testStateStoreRejectsSymlinkTarget(): void {
    $runtime = new PrivateRuntimeDirectory($this->privatePath);
    $store = new SyncWorkerStateStore($runtime);
    $target = $this->privatePath . '/outside-status.json';
    file_put_contents($target, '{}');
    self::assertTrue(symlink($target, $runtime->path() . '/sync-status.json'));

    $this->expectException(DbtngException::class);
    $store->write(new SyncWorkerSnapshot(
      123,
      SyncWorkerState::Idle,
      '2026-10-07T12:00:00+00:00',
      '2026-10-07T12:00:01+00:00',
      'MariaDB',
      'SQLite',
      'clean',
    ));
  }

  public function testRuntimeDirectoryRejectsLoosePermissions(): void {
    $runtime = new PrivateRuntimeDirectory($this->privatePath);
    $path = $runtime->path();
    self::assertTrue(chmod($path, 0755));

    $this->expectException(DbtngException::class);
    $runtime->path();
  }

  public function testSecondWorkerLockIsRejectedUntilRelease(): void {
    $lock = new SyncWorkerLock(new PrivateRuntimeDirectory($this->privatePath));
    $first = $lock->acquire();

    try {
      $this->expectException(WorkerAlreadyRunningException::class);
      $lock->acquire();
    }
    finally {
      $first->release();
    }
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
