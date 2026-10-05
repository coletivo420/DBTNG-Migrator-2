<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Model;

use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dbtng_migrator\Model\DatabaseTopology
 */
final class DatabaseTopologyTest extends TestCase {

  public function testDefaultTopologyUsesMysqlPrimaryAndSqliteStandby(): void {
    $topology = DatabaseTopology::default();

    self::assertSame(DatabaseEngine::MysqlFamily, $topology->primaryEngine);
    self::assertSame(DatabaseEngine::Sqlite, $topology->standbyEngine);
    self::assertTrue($topology->supportsProfile(ReplicationProfile::Full));
    self::assertTrue($topology->supportsProfile(ReplicationProfile::Clean));
  }

  public function testSqliteCanBePrimary(): void {
    $topology = new DatabaseTopology(
      DatabaseEngine::Sqlite,
      DatabaseEngine::MysqlFamily,
      'default',
      'dbtng_standby',
    );

    self::assertTrue($topology->sqliteIsPrimary());
    self::assertTrue($topology->supportsProfile(ReplicationProfile::Full));
    self::assertFalse($topology->supportsProfile(ReplicationProfile::Clean));
  }

  public function testSameEngineTopologyIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);

    new DatabaseTopology(
      DatabaseEngine::Sqlite,
      DatabaseEngine::Sqlite,
      'default',
      'dbtng_standby',
    );
  }

  public function testSameConnectionKeyIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);

    new DatabaseTopology(
      DatabaseEngine::MysqlFamily,
      DatabaseEngine::Sqlite,
      'default',
      'default',
    );
  }

}
