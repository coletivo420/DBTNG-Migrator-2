<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Policy;

use Drupal\dbtng_migrator\Model\ReplicationDecision;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Policy\CleanReplicationPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dbtng_migrator\Policy\CleanReplicationPolicy
 */
final class CleanReplicationPolicyTest extends TestCase {

  #[DataProvider('tablePolicyProvider')]
  public function testTablePolicy(string $tableName, ReplicationDecision $expected): void {
    $policy = new CleanReplicationPolicy();
    $table = new TableDefinition($tableName, []);

    self::assertSame($expected, $policy->tableDecision($table));
  }

  /**
   * @return iterable<string, array{string, \Drupal\dbtng_migrator\Model\ReplicationDecision}>
   */
  public static function tablePolicyProvider(): iterable {
    yield 'cache data is disposable' => ['cache_data', ReplicationDecision::SchemaOnly];
    yield 'sessions are disposable' => ['sessions', ReplicationDecision::SchemaOnly];
    yield 'semaphore locks are disposable' => ['semaphore', ReplicationDecision::SchemaOnly];
    yield 'batch state is disposable' => ['batch', ReplicationDecision::SchemaOnly];
    yield 'database logs are disposable' => ['watchdog', ReplicationDecision::SchemaOnly];
    yield 'queues are preserved' => ['queue', ReplicationDecision::Copy];
    yield 'flood state is preserved' => ['flood', ReplicationDecision::Copy];
    yield 'key value state is preserved' => ['key_value', ReplicationDecision::Copy];
    yield 'expiring key value state is preserved' => ['key_value_expire', ReplicationDecision::Copy];
    yield 'unknown table is conservatively preserved' => ['custom_business_data', ReplicationDecision::Copy];
  }

}
