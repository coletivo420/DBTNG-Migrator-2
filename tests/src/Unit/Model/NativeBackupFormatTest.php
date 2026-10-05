<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Model;

use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\NativeBackupFormat;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dbtng_migrator\Model\NativeBackupFormat
 */
final class NativeBackupFormatTest extends TestCase {

  public function testFormatsMapToExpectedEngines(): void {
    self::assertSame(
      DatabaseEngine::MysqlFamily,
      NativeBackupFormat::MysqlSql->databaseEngine(),
    );
    self::assertSame(
      DatabaseEngine::Sqlite,
      NativeBackupFormat::SqliteDatabase->databaseEngine(),
    );
  }

  public function testCompressionFlag(): void {
    self::assertFalse(NativeBackupFormat::MysqlSql->compressed());
    self::assertTrue(NativeBackupFormat::MysqlSqlGzip->compressed());
    self::assertFalse(NativeBackupFormat::SqliteDatabase->compressed());
    self::assertTrue(NativeBackupFormat::SqliteDatabaseGzip->compressed());
  }

}
