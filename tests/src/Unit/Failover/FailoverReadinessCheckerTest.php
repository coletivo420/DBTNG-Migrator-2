<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Failover;

use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Contract\ChangeCaptureInterface;
use Drupal\dbtng_migrator\Contract\DatabaseTopologyResolverInterface;
use Drupal\dbtng_migrator\Contract\ReconciliationEngineInterface;
use Drupal\dbtng_migrator\Contract\StandbyManifestReaderInterface;
use Drupal\dbtng_migrator\Failover\FailoverReadinessChecker;
use Drupal\dbtng_migrator\Model\ChangeCaptureStatus;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseProduct;
use Drupal\dbtng_migrator\Model\DatabaseRole;
use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\FailoverBlockerCode;
use Drupal\dbtng_migrator\Model\FailoverReadinessStatus;
use Drupal\dbtng_migrator\Model\ReconciliationReport;
use Drupal\dbtng_migrator\Model\ReconciliationStatus;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\ResolvedDatabase;
use Drupal\dbtng_migrator\Model\ResolvedTopology;
use PHPUnit\Framework\TestCase;

/**
 * Tests conservative read-only failover readiness.
 */
final class FailoverReadinessCheckerTest extends TestCase {

  public function testFullHealthyPublishedStandbyIsDataPlaneReady(): void {
    $checker = new FailoverReadinessChecker(
      $this->resolver(ReplicationProfile::Full),
      $this->capture(TRUE, TRUE, 0),
      $this->reconciliation(ReconciliationStatus::Match, TRUE, TRUE, ReplicationProfile::Full),
      $this->manifest([
        'generation_id' => str_repeat('a', 32),
        'lifecycle_state' => 'published',
        'primary_engine' => 'mysql',
        'standby_engine' => 'sqlite',
        'profile' => 'full',
        'activatable' => TRUE,
        'full_fidelity' => TRUE,
        'schema_fingerprint' => str_repeat('b', 64),
      ]),
    );

    $report = $checker->check();

    self::assertSame(FailoverReadinessStatus::Ready, $report->status);
    self::assertSame([], $report->blockers);
    self::assertTrue($report->ready());
    self::assertTrue($report->toArray()['external_write_fence_required']);
    self::assertTrue($report->toArray()['continuous_worker_stop_required']);
    self::assertFalse($report->toArray()['automatic_promotion']);
  }

  public function testCleanStandbyIsNeverPromotionReady(): void {
    $checker = new FailoverReadinessChecker(
      $this->resolver(ReplicationProfile::Clean),
      $this->capture(TRUE, TRUE, 0),
      $this->reconciliation(ReconciliationStatus::Match, TRUE, TRUE, ReplicationProfile::Clean),
      $this->manifest([
        'generation_id' => str_repeat('a', 32),
        'lifecycle_state' => 'published',
        'primary_engine' => 'mysql',
        'standby_engine' => 'sqlite',
        'profile' => 'clean',
        'activatable' => FALSE,
        'full_fidelity' => FALSE,
        'schema_fingerprint' => str_repeat('b', 64),
      ]),
    );

    $report = $checker->check();
    $codes = array_map(static fn ($blocker): FailoverBlockerCode => $blocker->code, $report->blockers);

    self::assertSame(FailoverReadinessStatus::NotReady, $report->status);
    self::assertContains(FailoverBlockerCode::ProfileNotFull, $codes);
    self::assertContains(FailoverBlockerCode::ManifestProfileMismatch, $codes);
    self::assertContains(FailoverBlockerCode::ManifestNotActivatable, $codes);
    self::assertContains(FailoverBlockerCode::ManifestNotFullFidelity, $codes);
  }

  public function testPendingCaptureAndDriftRemainBlocked(): void {
    $checker = new FailoverReadinessChecker(
      $this->resolver(ReplicationProfile::Full),
      $this->capture(TRUE, FALSE, 7),
      $this->reconciliation(ReconciliationStatus::Drift, FALSE, FALSE, ReplicationProfile::Full),
      $this->manifest(NULL),
    );

    $report = $checker->check();
    $codes = array_map(static fn ($blocker): FailoverBlockerCode => $blocker->code, $report->blockers);

    self::assertSame(FailoverReadinessStatus::NotReady, $report->status);
    self::assertContains(FailoverBlockerCode::CaptureUnhealthy, $codes);
    self::assertContains(FailoverBlockerCode::PendingEvents, $codes);
    self::assertContains(FailoverBlockerCode::ReconciliationNotMatch, $codes);
    self::assertContains(FailoverBlockerCode::IntegrityFailed, $codes);
    self::assertContains(FailoverBlockerCode::ManifestInvalid, $codes);
  }

  public function testLegacyOrUnpublishedManifestRequiresFreshFullBaseline(): void {
    $checker = new FailoverReadinessChecker(
      $this->resolver(ReplicationProfile::Full),
      $this->capture(TRUE, TRUE, 0),
      $this->reconciliation(ReconciliationStatus::Match, TRUE, TRUE, ReplicationProfile::Full),
      $this->manifest([
        'profile' => 'full',
        'activatable' => TRUE,
        'schema_fingerprint' => str_repeat('b', 64),
      ]),
    );

    $report = $checker->check();
    $codes = array_map(static fn ($blocker): FailoverBlockerCode => $blocker->code, $report->blockers);

    self::assertContains(FailoverBlockerCode::ManifestNotFullFidelity, $codes);
    self::assertContains(FailoverBlockerCode::ManifestNotPublished, $codes);
    self::assertContains(FailoverBlockerCode::ManifestTopologyMismatch, $codes);
  }

  private function resolver(ReplicationProfile $profile): DatabaseTopologyResolverInterface {
    $connection = $this->createMock(Connection::class);
    $resolver = $this->createMock(DatabaseTopologyResolverInterface::class);
    $resolver->method('resolve')->willReturn(new ResolvedTopology(
      new DatabaseTopology(DatabaseEngine::MysqlFamily, DatabaseEngine::Sqlite),
      new ResolvedDatabase(
        DatabaseRole::Primary,
        'default',
        $connection,
        DatabaseEngine::MysqlFamily,
        DatabaseProduct::MariaDb,
        '11.8',
        'mysql:primary',
      ),
      new ResolvedDatabase(
        DatabaseRole::Standby,
        'dbtng_standby',
        $connection,
        DatabaseEngine::Sqlite,
        DatabaseProduct::Sqlite,
        '3.46',
        'sqlite:standby',
        '/tmp/standby.sqlite',
      ),
      $profile,
    ));
    return $resolver;
  }

  private function capture(bool $installed, bool $healthy, int $pending): ChangeCaptureInterface {
    $capture = $this->createMock(ChangeCaptureInterface::class);
    $capture->method('status')->willReturn(new ChangeCaptureStatus(
      DatabaseEngine::MysqlFamily,
      $installed,
      $healthy,
      58,
      174,
      $healthy ? 174 : 171,
      $pending,
      0,
    ));
    return $capture;
  }

  private function reconciliation(
    ReconciliationStatus $status,
    bool $integrity,
    bool $manifest,
    ReplicationProfile $profile,
  ): ReconciliationEngineInterface {
    $engine = $this->createMock(ReconciliationEngineInterface::class);
    $engine->method('reconcile')->willReturn(new ReconciliationReport(
      $status,
      $profile,
      'MariaDB',
      'SQLite',
      [],
      [],
      $integrity,
      $manifest,
      str_repeat('b', 64),
    ));
    return $engine;
  }

  /**
   * @param array<string, mixed>|null $metadata
   *   Current standby metadata.
   */
  private function manifest(?array $metadata): StandbyManifestReaderInterface {
    $reader = $this->createMock(StandbyManifestReaderInterface::class);
    $reader->method('readCurrent')->with('sqlite:standby')->willReturn($metadata);
    return $reader;
  }

}
