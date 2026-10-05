<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Policy;

use Drupal\dbtng_migrator\Contract\ReplicationPolicyInterface;
use Drupal\dbtng_migrator\Model\ReplicationDecision;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\TableDefinition;

/**
 * Conservative clean standby policy.
 *
 * Schema is always retained by the snapshot builder. This class only decides
 * whether source rows should be copied for a given table.
 */
final class CleanReplicationPolicy implements ReplicationPolicyInterface {

  /**
   * Tables whose rows are deliberately omitted in the clean profile.
   *
   * @var list<string>
   */
  private const SCHEMA_ONLY_TABLES = [
    'sessions',
    'semaphore',
    'batch',
    'watchdog',
  ];

  public function id(): ReplicationProfile {
    return ReplicationProfile::Clean;
  }

  public function tableDecision(TableDefinition $table): ReplicationDecision {
    if (str_starts_with($table->name, 'cache_')) {
      return ReplicationDecision::SchemaOnly;
    }

    if (in_array($table->name, self::SCHEMA_ONLY_TABLES, TRUE)) {
      return ReplicationDecision::SchemaOnly;
    }

    // queue, flood, key_value, key_value_expire and unknown/custom tables are
    // intentionally preserved unless a future explicit policy says otherwise.
    return ReplicationDecision::Copy;
  }

}
