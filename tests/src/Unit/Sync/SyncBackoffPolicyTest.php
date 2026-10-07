<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Sync;

use Drupal\dbtng_migrator\Model\SyncWorkerConfiguration;
use Drupal\dbtng_migrator\Sync\SyncBackoffPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests capped exponential continuous-sync backoff.
 */
final class SyncBackoffPolicyTest extends TestCase {

  public function testProgressionAndCap(): void {
    $configuration = new SyncWorkerConfiguration();
    $policy = new SyncBackoffPolicy();

    self::assertSame(0, $policy->delay(0, $configuration));
    self::assertSame(1, $policy->delay(1, $configuration));
    self::assertSame(2, $policy->delay(2, $configuration));
    self::assertSame(4, $policy->delay(3, $configuration));
    self::assertSame(8, $policy->delay(4, $configuration));
    self::assertSame(16, $policy->delay(5, $configuration));
    self::assertSame(30, $policy->delay(6, $configuration));
    self::assertSame(30, $policy->delay(20, $configuration));
  }

  public function testConfigurationRejectsStaleThresholdInsideNormalSleepWindow(): void {
    $this->expectException(\InvalidArgumentException::class);
    new SyncWorkerConfiguration(500, 1, 30, 1, 30, 60, 60, 60);
  }

}
