<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Policy;

use Drupal\dbtng_migrator\Model\ReplicationDecision;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Policy\FullReplicationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dbtng_migrator\Policy\FullReplicationPolicy
 */
final class FullReplicationPolicyTest extends TestCase {

  public function testPolicyId(): void {
    self::assertSame(ReplicationProfile::Full, (new FullReplicationPolicy())->id());
  }

  public function testUnknownTableIsCopied(): void {
    $policy = new FullReplicationPolicy();

    self::assertSame(
      ReplicationDecision::Copy,
      $policy->tableDecision(new TableDefinition('anything', [])),
    );
  }

}
