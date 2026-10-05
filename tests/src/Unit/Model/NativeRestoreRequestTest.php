<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Model;

use Drupal\dbtng_migrator\Model\NativeBackupFormat;
use Drupal\dbtng_migrator\Model\NativeRestoreRequest;
use Drupal\dbtng_migrator\Model\NonEmptyDestinationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dbtng_migrator\Model\NativeRestoreRequest
 */
final class NativeRestoreRequestTest extends TestCase {

  public function testDefaultsToAbortOnNonEmptyDestination(): void {
    $request = new NativeRestoreRequest(
      '/private/dbtng/backup.sqlite',
      NativeBackupFormat::SqliteDatabase,
    );

    self::assertSame(
      NonEmptyDestinationPolicy::Abort,
      $request->nonEmptyPolicy,
    );
  }

  public function testBackupThenClearCanBeSelectedExplicitly(): void {
    $request = new NativeRestoreRequest(
      '/private/dbtng/backup.sql.gz',
      NativeBackupFormat::MysqlSqlGzip,
      NonEmptyDestinationPolicy::BackupThenClear,
    );

    self::assertSame(
      NonEmptyDestinationPolicy::BackupThenClear,
      $request->nonEmptyPolicy,
    );
  }

  public function testEmptyBackupPathIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);

    new NativeRestoreRequest(
      '',
      NativeBackupFormat::SqliteDatabase,
    );
  }

}
