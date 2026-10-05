<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Model;

use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\SnapshotRequest;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dbtng_migrator\Model\SnapshotRequest
 */
final class SnapshotRequestTest extends TestCase {

  public function testDefaultRequestUsesFullProfile(): void {
    $request = new SnapshotRequest();

    self::assertSame(DatabaseEngine::MysqlFamily, $request->topology->primaryEngine);
    self::assertSame(DatabaseEngine::Sqlite, $request->topology->standbyEngine);
    self::assertSame(ReplicationProfile::Full, $request->profile);
  }

  public function testMysqlPrimarySupportsCleanSqliteStandby(): void {
    $request = new SnapshotRequest(ReplicationProfile::Clean);

    self::assertSame(ReplicationProfile::Clean, $request->profile);
    self::assertTrue($request->topology->sqliteIsStandby());
  }

  public function testSqlitePrimarySupportsFullStandbyProfile(): void {
    $topology = new DatabaseTopology(
      DatabaseEngine::Sqlite,
      DatabaseEngine::MysqlFamily,
      'default',
      'dbtng_standby',
    );

    $request = new SnapshotRequest(ReplicationProfile::Full, TRUE, $topology);

    self::assertSame(DatabaseEngine::Sqlite, $request->topology->primaryEngine);
    self::assertSame(ReplicationProfile::Full, $request->profile);
  }

  public function testSqlitePrimaryRejectsCleanStandbyProfile(): void {
    $topology = new DatabaseTopology(
      DatabaseEngine::Sqlite,
      DatabaseEngine::MysqlFamily,
      'default',
      'dbtng_standby',
    );

    $this->expectException(\InvalidArgumentException::class);
    new SnapshotRequest(ReplicationProfile::Clean, TRUE, $topology);
  }

}
