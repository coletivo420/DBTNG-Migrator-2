<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Model;

use Drupal\dbtng_migrator\Model\NonEmptyDestinationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dbtng_migrator\Model\NonEmptyDestinationPolicy
 */
final class NonEmptyDestinationPolicyTest extends TestCase {

  public function testAbortIsNotDestructive(): void {
    self::assertFalse(NonEmptyDestinationPolicy::Abort->destructive());
    self::assertFalse(NonEmptyDestinationPolicy::Abort->createsSafetyBackup());
  }

  public function testBackupThenClearIsDestructiveAndBackedUp(): void {
    self::assertTrue(NonEmptyDestinationPolicy::BackupThenClear->destructive());
    self::assertTrue(NonEmptyDestinationPolicy::BackupThenClear->createsSafetyBackup());
  }

  public function testClearIsDestructiveWithoutBackup(): void {
    self::assertTrue(NonEmptyDestinationPolicy::Clear->destructive());
    self::assertFalse(NonEmptyDestinationPolicy::Clear->createsSafetyBackup());
  }

}
