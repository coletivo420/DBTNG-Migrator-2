<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\dbtng_migrator\Model\ChangeIdentityKind;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\ChangeOperation;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\DirtyBatch;
use Drupal\dbtng_migrator\Model\DirtyIdentity;
use Drupal\dbtng_migrator\Model\ReplicationDecision;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Policy\CleanReplicationPolicy;

/**
 * Collapses committed mutations into canonical current-state identities. */
final class DirtyEventReducer {

  public function __construct(private readonly CleanReplicationPolicy $cleanPolicy) {}

  /**
   * Reduces exact events using schema-ordered primary-key values.
   *
   * @param list<\Drupal\dbtng_migrator\Model\ChangeRecord> $events
   */
  public function reduce(array $events, DatabaseInventory $inventory, ReplicationProfile $profile): DirtyBatch {
    $tables = [];
    foreach ($inventory->tables as $table) {
      $tables[$table->name] = $table;
    }
    $groups = [];
    $eventIds = [];
    foreach ($events as $event) {
      if (!isset($tables[$event->tableName])) {
        throw new DbtngException('Pending change refers to a missing application table; rebuild is required.');
      }
      $table = $tables[$event->tableName];
      $eventIds[] = $event->id;
      if ($profile === ReplicationProfile::Clean && $this->cleanPolicy->tableDecision($table) === ReplicationDecision::SchemaOnly) {
        $groups[$table->name]['table'] = TRUE;
        $groups[$table->name]['event_ids'][] = $event->id;
        continue;
      }
      if ($event->identityKind->value === 'table' || $table->primaryKey === []) {
        $groups[$table->name]['table'] = TRUE;
        $groups[$table->name]['event_ids'][] = $event->id;
        continue;
      }
      $keys = [];
      if ($event->operation !== ChangeOperation::Delete && $event->key !== NULL) {
        $keys[] = $event->key;
      }
      if ($event->operation !== ChangeOperation::Insert && $event->oldKey !== NULL) {
        $keys[] = $event->oldKey;
      }
      if ($keys === []) {
        throw new DbtngException('Captured row event has no usable key; rebuild is required.');
      }
      foreach ($keys as $key) {
        $canonical = $this->canonicalKey($table, $key);
        $identityKey = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $groups[$table->name]['rows'][$identityKey]['key'] = $canonical;
        $groups[$table->name]['rows'][$identityKey]['event_ids'][] = $event->id;
      }
    }

    $identities = [];
    foreach ($groups as $tableName => $group) {
      $table = $tables[$tableName];
      if (!empty($group['table'])) {
        $ids = $group['event_ids'] ?? [];
        foreach ($group['rows'] ?? [] as $row) {
          $ids = array_merge($ids, $row['event_ids']);
        }
        $ids = array_values(array_unique($ids));
        $identities[] = new DirtyIdentity($tableName, ChangeIdentityKind::Table, NULL, $ids);
        continue;
      }
      foreach ($group['rows'] ?? [] as $row) {
        $identities[] = new DirtyIdentity(
          $tableName,
          ChangeIdentityKind::PrimaryKey,
          $row['key'],
          array_values(array_unique($row['event_ids'])),
        );
      }
    }
    return new DirtyBatch($events, $identities, array_values(array_unique($eventIds)));
  }

  /**
   * Orders a possibly reordered JSON key map using the physical primary key.
   *
   * @param array<string, int|string|null> $key
   *
   * @return array<string, int|string|null>
   */
  private function canonicalKey(TableDefinition $table, array $key): array {
    $canonical = [];
    foreach ($table->primaryKey as $column) {
      if (!array_key_exists($column, $key)) {
        throw new DbtngException(sprintf('Captured key for "%s" is missing primary-key column "%s".', $table->name, $column));
      }
      $canonical[$column] = $key[$column];
    }
    if (count($canonical) !== count($key)) {
      throw new DbtngException(sprintf('Captured key for "%s" contains unexpected columns.', $table->name));
    }
    return $canonical;
  }

}
