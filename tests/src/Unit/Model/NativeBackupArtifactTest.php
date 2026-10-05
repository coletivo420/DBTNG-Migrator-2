<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Model;

use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseRole;
use Drupal\dbtng_migrator\Model\NativeBackupArtifact;
use Drupal\dbtng_migrator\Model\NativeBackupFormat;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dbtng_migrator\Model\NativeBackupArtifact
 */
final class NativeBackupArtifactTest extends TestCase {

  public function testValidArtifact(): void {
    $artifact = new NativeBackupArtifact(
      DatabaseEngine::MysqlFamily,
      DatabaseRole::Primary,
      NativeBackupFormat::MysqlSqlGzip,
      '/private/dbtng/backup.sql.gz',
      'backup.sql.gz',
      'application/gzip',
      1024,
      str_repeat('a', 64),
    );

    self::assertSame(DatabaseEngine::MysqlFamily, $artifact->engine);
    self::assertSame(1024, $artifact->bytes);
  }

  public function testFormatMustMatchEngine(): void {
    $this->expectException(\InvalidArgumentException::class);

    new NativeBackupArtifact(
      DatabaseEngine::Sqlite,
      DatabaseRole::Standby,
      NativeBackupFormat::MysqlSql,
      '/private/dbtng/backup.sql',
      'backup.sql',
      'application/sql',
      1,
      str_repeat('b', 64),
    );
  }

  public function testChecksumMustBeSha256(): void {
    $this->expectException(\InvalidArgumentException::class);

    new NativeBackupArtifact(
      DatabaseEngine::Sqlite,
      DatabaseRole::Standby,
      NativeBackupFormat::SqliteDatabase,
      '/private/dbtng/backup.sqlite',
      'backup.sqlite',
      'application/vnd.sqlite3',
      1,
      'invalid',
    );
  }

}
