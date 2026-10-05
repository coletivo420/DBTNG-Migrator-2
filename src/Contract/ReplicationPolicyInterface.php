<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\ReplicationDecision;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\TableDefinition;

/**
 * Classifies how source data is represented in the standby.
 */
interface ReplicationPolicyInterface {

  public function id(): ReplicationProfile;

  public function tableDecision(TableDefinition $table): ReplicationDecision;

}
