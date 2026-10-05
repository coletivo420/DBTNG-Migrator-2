<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Policy;

use Drupal\dbtng_migrator\Contract\ReplicationPolicyInterface;
use Drupal\dbtng_migrator\Model\ReplicationDecision;
use Drupal\dbtng_migrator\Model\TableDefinition;

/**
 * Preserves all table data selected for replication.
 */
final class FullReplicationPolicy implements ReplicationPolicyInterface {

  public function id(): string {
    return 'full';
  }

  public function tableDecision(TableDefinition $table): ReplicationDecision {
    return ReplicationDecision::Copy;
  }

}
