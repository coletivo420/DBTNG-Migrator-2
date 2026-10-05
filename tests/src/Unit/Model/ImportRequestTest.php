<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Model;

use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\ImportRequest;
use Drupal\dbtng_migrator\Model\NonEmptyDestinationPolicy;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dbtng_migrator\Model\ImportRequest
 */
final class ImportRequestTest extends TestCase {

  public function testDefaultImportUsesMysqlToSqliteFullProfile(): void {
    $request = new ImportRequest();

    self::assertSame(DatabaseEngine::MysqlFamily, $request->topology->primaryEngine);
    self::assertSame(DatabaseEngine::Sqlite, $request->topology->standbyEngine);
    self::assertSame(ReplicationProfile::Full, $request->profile);
    self::assertSame(NonEmptyDestinationPolicy::Abort, $request->nonEmptyPolicy);
  }

  public function testMysqlPrimaryCanImportCleanIntoSqliteStandby(): void {
    $request = new ImportRequest(ReplicationProfile::Clean);

    self::assertTrue($request->topology->sqliteIsStandby());
    self::assertSame(ReplicationProfile::Clean, $request->profile);
  }

  public function testSqlitePrimaryCanImportFullIntoMysqlStandby(): void {
    $topology = new DatabaseTopology(
      DatabaseEngine::Sqlite,
      DatabaseEngine::MysqlFamily,
      'default',
      'dbtng_standby',
    );

    $request = new ImportRequest(
      ReplicationProfile::Full,
      TRUE,
      NonEmptyDestinationPolicy::BackupThenClear,
      $topology,
    );

    self::assertSame(DatabaseEngine::Sqlite, $request->topology->primaryEngine);
    self::assertSame(DatabaseEngine::MysqlFamily, $request->topology->standbyEngine);
    self::assertSame(NonEmptyDestinationPolicy::BackupThenClear, $request->nonEmptyPolicy);
  }

  public function testSqlitePrimaryCannotUseCleanMysqlImport(): void {
    $topology = new DatabaseTopology(
      DatabaseEngine::Sqlite,
      DatabaseEngine::MysqlFamily,
      'default',
      'dbtng_standby',
    );

    $this->expectException(\InvalidArgumentException::class);
    new ImportRequest(
      ReplicationProfile::Clean,
      TRUE,
      NonEmptyDestinationPolicy::Abort,
      $topology,
    );
  }

}
