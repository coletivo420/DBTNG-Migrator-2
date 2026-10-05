<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Model;

use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\SnapshotRequest;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dbtng_migrator\Model\SnapshotRequest
 */
final class SnapshotRequestTest extends TestCase {

  public function testDefaultRequestUsesCleanSqliteStandby(): void {
    $request = new SnapshotRequest();

    self::assertSame(DatabaseEngine::MysqlFamily, $request->topology->primaryEngine);
    self::assertSame(DatabaseEngine::Sqlite, $request->topology->standbyEngine);
    self::assertSame('clean', $request->profile);
  }

  public function testSqlitePrimarySupportsFullStandbyProfile(): void {
    $topology = new DatabaseTopology(
      DatabaseEngine::Sqlite,
      DatabaseEngine::MysqlFamily,
      'default',
      'dbtng_standby',
    );

    $request = new SnapshotRequest('full', TRUE, $topology);

    self::assertSame(DatabaseEngine::Sqlite, $request->topology->primaryEngine);
    self::assertSame('full', $request->profile);
  }

  public function testSqlitePrimaryRejectsCleanStandbyProfile(): void {
    $topology = new DatabaseTopology(
      DatabaseEngine::Sqlite,
      DatabaseEngine::MysqlFamily,
      'default',
      'dbtng_standby',
    );

    $this->expectException(\InvalidArgumentException::class);
    new SnapshotRequest('clean', TRUE, $topology);
  }

}
