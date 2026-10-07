<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\ChangeCapture;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\dbtng_migrator\Contract\ChangeCaptureAdapterInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\CaptureIdentityDefinition;
use Drupal\dbtng_migrator\Model\ChangeCaptureStatus;
use Drupal\dbtng_migrator\Model\ChangeIdentityKind;
use Drupal\dbtng_migrator\Model\ChangeOperation;
use Drupal\dbtng_migrator\Model\ChangeRecord;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;

/**
 * Captures SQLite mutations atomically inside the originating transaction.
 */
final class SqliteChangeCaptureAdapter implements ChangeCaptureAdapterInterface {

  public function __construct(private readonly ChangeIdentityPlanner $identityPlanner) {}

  public function supports(DatabaseEngine $engine): bool {
    return $engine === DatabaseEngine::Sqlite;
  }

  public function install(Connection $connection, DatabaseInventory $inventory): ChangeCaptureStatus {
    $this->assertConnection($connection);
    $log = SqlIdentifier::quote($connection, CaptureSchema::LOG_TABLE);
    $connection->query(
      'CREATE TABLE IF NOT EXISTS ' . $log
      . " (event_id INTEGER PRIMARY KEY AUTOINCREMENT, table_name TEXT NOT NULL, operation TEXT NOT NULL CHECK (operation IN ('insert','update','delete')), identity_kind TEXT NOT NULL CHECK (identity_kind IN ('primary_key','table')), key_json TEXT NULL, old_key_json TEXT NULL, captured_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')))",
    );
    $connection->query(
      'CREATE INDEX IF NOT EXISTS "dbtng_migrator_change_log_table_seq" ON ' . $log . ' (table_name, event_id)',
    );

    foreach ($inventory->tables as $table) {
      $identity = $this->identityPlanner->plan($table);
      foreach (ChangeOperation::cases() as $operation) {
        $name = CaptureSchema::triggerName($table->name, $operation);
        if ($this->triggerExists($connection, $name)) {
          continue;
        }
        $this->executeTriggerDdl($connection, $this->triggerSql($connection, $table, $identity, $operation, $name));
      }
    }

    return $this->status($connection, $inventory);
  }

  public function uninstall(Connection $connection): void {
    $this->assertConnection($connection);
    foreach ($this->triggerNames($connection) as $name) {
      $connection->query('DROP TRIGGER IF EXISTS ' . SqlIdentifier::quote($connection, $name));
    }
    $connection->query('DROP TABLE IF EXISTS ' . SqlIdentifier::quote($connection, CaptureSchema::LOG_TABLE));
  }

  public function status(Connection $connection, DatabaseInventory $inventory): ChangeCaptureStatus {
    $this->assertConnection($connection);
    $installed = $this->logTableExists($connection);
    $triggerCount = count($this->triggerNames($connection));
    $trackedTables = count($inventory->tables);
    $expectedTriggers = $trackedTables * 3;
    $tableDirty = 0;
    foreach ($inventory->tables as $table) {
      if ($this->identityPlanner->plan($table)->kind === ChangeIdentityKind::Table) {
        $tableDirty++;
      }
    }
    [$pending, $oldest, $newest] = $installed ? $this->backlog($connection) : [0, NULL, NULL];
    return new ChangeCaptureStatus(
      DatabaseEngine::Sqlite,
      $installed,
      $installed && $triggerCount === $expectedTriggers,
      $trackedTables,
      $expectedTriggers,
      $triggerCount,
      $pending,
      $tableDirty,
      $oldest,
      $newest,
      [
        'DDL is outside row-trigger capture and must be detected through schema fingerprint/reconciliation.',
        'Event IDs identify durable records and are acknowledged individually; they are not a cross-engine transaction clock.',
      ],
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return list<\Drupal\dbtng_migrator\Model\ChangeRecord>
   *   Currently visible pending events.
   */
  public function pending(Connection $connection, int $limit = 500): array {
    $this->assertLimit($limit);
    if (!$this->logTableExists($connection)) {
      return [];
    }
    $statement = $connection->query(
      'SELECT event_id, table_name, operation, identity_kind, key_json, old_key_json, captured_at FROM '
      . SqlIdentifier::quote($connection, CaptureSchema::LOG_TABLE)
      . ' ORDER BY event_id ASC LIMIT ' . $limit,
    );
    if ($statement === NULL) {
      throw new DbtngException('Unable to read the SQLite durable change log.');
    }
    $records = [];
    foreach ($statement->fetchAll(FetchAs::Associative) as $row) {
      $records[] = $this->record($row);
    }
    return $records;
  }

  public function acknowledge(Connection $connection, array $eventIds): void {
    if ($eventIds === []) {
      return;
    }
    $parameters = [];
    $placeholders = [];
    foreach (array_values(array_unique($eventIds)) as $offset => $id) {
      if ($id < 1) {
        throw new \InvalidArgumentException('Capture event IDs must be positive integers.');
      }
      $placeholder = ':event_' . $offset;
      $placeholders[] = $placeholder;
      $parameters[$placeholder] = $id;
    }
    $connection->query(
      'DELETE FROM ' . SqlIdentifier::quote($connection, CaptureSchema::LOG_TABLE)
      . ' WHERE event_id IN (' . implode(', ', $placeholders) . ')',
      $parameters,
    );
  }

  private function triggerSql(
    Connection $connection,
    TableDefinition $table,
    CaptureIdentityDefinition $identity,
    ChangeOperation $operation,
    string $triggerName,
  ): string {
    $key = $operation === ChangeOperation::Delete ? 'NULL' : $this->jsonObject($connection, $identity, 'NEW');
    $oldKey = $operation === ChangeOperation::Insert ? 'NULL' : $this->jsonObject($connection, $identity, 'OLD');
    return 'CREATE TRIGGER ' . SqlIdentifier::quote($connection, $triggerName)
      . ' AFTER ' . strtoupper($operation->value)
      . ' ON ' . SqlIdentifier::quote($connection, $table->name)
      . ' BEGIN INSERT INTO ' . SqlIdentifier::quote($connection, CaptureSchema::LOG_TABLE)
      . ' (table_name, operation, identity_kind, key_json, old_key_json) VALUES ('
      . $this->literal($table->name) . ', '
      . $this->literal($operation->value) . ', '
      . $this->literal($identity->kind->value) . ', '
      . $key . ', ' . $oldKey . '); END';
  }

  private function jsonObject(Connection $connection, CaptureIdentityDefinition $identity, string $rowPrefix): string {
    if ($identity->kind === ChangeIdentityKind::Table) {
      return 'NULL';
    }
    $pairs = [];
    foreach ($identity->columns as $column) {
      $pairs[] = $this->literal($column);
      $pairs[] = $rowPrefix . '.' . SqlIdentifier::quote($connection, $column);
    }
    return 'json_object(' . implode(', ', $pairs) . ')';
  }

  private function logTableExists(Connection $connection): bool {
    $statement = $connection->query(
      "SELECT COUNT(*) FROM sqlite_schema WHERE type = 'table' AND name = :name",
      [':name' => CaptureSchema::LOG_TABLE],
    );
    return $statement !== NULL && (int) $statement->fetchField() === 1;
  }

  private function triggerExists(Connection $connection, string $name): bool {
    $statement = $connection->query(
      "SELECT COUNT(*) FROM sqlite_schema WHERE type = 'trigger' AND name = :name",
      [':name' => $name],
    );
    return $statement !== NULL && (int) $statement->fetchField() === 1;
  }

  /**
   * Lists DBTNG capture triggers in the SQLite schema.
   *
   * @return list<string>
   *   Capture trigger names.
   */
  private function triggerNames(Connection $connection): array {
    $statement = $connection->query(
      "SELECT name FROM sqlite_schema WHERE type = 'trigger' AND substr(name, 1, :length) = :prefix ORDER BY name",
      [':length' => strlen(CaptureSchema::TRIGGER_PREFIX), ':prefix' => CaptureSchema::TRIGGER_PREFIX],
    );
    if ($statement === NULL) {
      return [];
    }
    return array_values(array_map(
      static fn (array $row): string => (string) $row['name'],
      $statement->fetchAll(FetchAs::Associative),
    ));
  }

  /**
   * Returns pending count and visible event-ID bounds.
   *
   * @return array{0: int, 1: int|null, 2: int|null}
   *   Pending count, oldest ID and newest ID.
   */
  private function backlog(Connection $connection): array {
    $statement = $connection->query(
      'SELECT COUNT(*) AS pending, MIN(event_id) AS oldest, MAX(event_id) AS newest FROM '
      . SqlIdentifier::quote($connection, CaptureSchema::LOG_TABLE),
    );
    $row = $statement?->fetchAssoc();
    if (!is_array($row)) {
      return [0, NULL, NULL];
    }
    return [
      (int) $row['pending'],
      $row['oldest'] === NULL ? NULL : (int) $row['oldest'],
      $row['newest'] === NULL ? NULL : (int) $row['newest'],
    ];
  }

  /**
   * Converts one database row into a typed capture record.
   *
   * @param array<string, mixed> $row
   *   Raw change-log row.
   */
  private function record(array $row): ChangeRecord {
    return new ChangeRecord(
      (int) $row['event_id'],
      (string) $row['table_name'],
      ChangeOperation::from((string) $row['operation']),
      ChangeIdentityKind::from((string) $row['identity_kind']),
      $this->decodeKey($row['key_json']),
      $this->decodeKey($row['old_key_json']),
      (string) $row['captured_at'],
    );
  }

  /**
   * Decodes one optional primary-key JSON document.
   *
   * @return array<string, int|string|null>|null
   *   Decoded key values, or NULL when the event has no row key.
   */
  private function decodeKey(mixed $json): ?array {
    if ($json === NULL) {
      return NULL;
    }
    $value = json_decode((string) $json, TRUE, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    if (!is_array($value)) {
      throw new DbtngException('Captured primary-key JSON is invalid.');
    }
    return $value;
  }

  private function literal(string $value): string {
    return "'" . str_replace("'", "''", $value) . "'";
  }

  /**
   * Executes one SQLite trigger DDL statement through the client connection.
   *
   * Drupal's generic query guard rejects semicolons, while SQLite trigger
   * bodies require a statement terminator before END.
   */
  private function executeTriggerDdl(Connection $connection, string $sql): void {
    $client = $connection->getClientConnection();
    if (!$client instanceof \PDO || $client->exec($sql) === FALSE) {
      throw new DbtngException('Unable to create the SQLite change-capture trigger.');
    }
  }

  private function assertConnection(Connection $connection): void {
    if (!in_array(strtolower($connection->driver()), ['sqlite', 'sqlite3'], TRUE)) {
      throw new \InvalidArgumentException('SQLite capture requires Drupal’s SQLite driver.');
    }
  }

  private function assertLimit(int $limit): void {
    if ($limit < 1 || $limit > 5000) {
      throw new \InvalidArgumentException('Capture batch limit must be between 1 and 5000.');
    }
  }

}
