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
 * Captures MySQL-family mutations in the same InnoDB transaction as writes.
 */
final class MysqlFamilyChangeCaptureAdapter implements ChangeCaptureAdapterInterface {

  public function __construct(private readonly ChangeIdentityPlanner $identityPlanner) {}

  public function supports(DatabaseEngine $engine): bool {
    return $engine === DatabaseEngine::MysqlFamily;
  }

  public function install(Connection $connection, DatabaseInventory $inventory): ChangeCaptureStatus {
    $this->assertConnection($connection);
    $connection->query(
      'CREATE TABLE IF NOT EXISTS ' . SqlIdentifier::quote($connection, CaptureSchema::LOG_TABLE)
      . ' (sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, table_name VARCHAR(255) NOT NULL, operation VARCHAR(8) NOT NULL, identity_kind VARCHAR(16) NOT NULL, key_json LONGTEXT NULL, old_key_json LONGTEXT NULL, captured_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY (sequence), KEY dbtng_capture_table_seq (table_name, sequence)) ENGINE=InnoDB',
    );

    foreach ($inventory->tables as $table) {
      $identity = $this->identityPlanner->plan($table);
      foreach (ChangeOperation::cases() as $operation) {
        $name = CaptureSchema::triggerName($table->name, $operation);
        if ($this->triggerExists($connection, $name)) {
          continue;
        }
        $connection->query($this->triggerSql($connection, $table, $identity, $operation, $name));
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
      DatabaseEngine::MysqlFamily,
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
        'TRUNCATE and DDL are not captured by row triggers; schema drift requires reconciliation/rebuild.',
        'Event IDs identify durable records but are not a transaction commit-order watermark.',
      ],
    );
  }

  public function pending(Connection $connection, int $limit = 500): array {
    $this->assertLimit($limit);
    if (!$this->logTableExists($connection)) {
      return [];
    }
    $statement = $connection->query(
      'SELECT sequence, table_name, operation, identity_kind, key_json, old_key_json, captured_at FROM '
      . SqlIdentifier::quote($connection, CaptureSchema::LOG_TABLE)
      . ' ORDER BY sequence ASC LIMIT ' . $limit,
    );
    if ($statement === NULL) {
      throw new DbtngException('Unable to read the MySQL-family durable change log.');
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
      if (!is_int($id) || $id < 1) {
        throw new \InvalidArgumentException('Capture event IDs must be positive integers.');
      }
      $placeholder = ':event_' . $offset;
      $placeholders[] = $placeholder;
      $parameters[$placeholder] = $id;
    }
    $connection->query(
      'DELETE FROM ' . SqlIdentifier::quote($connection, CaptureSchema::LOG_TABLE)
      . ' WHERE sequence IN (' . implode(', ', $placeholders) . ')',
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
    $rowPrefix = $operation === ChangeOperation::Delete ? 'OLD' : 'NEW';
    $key = $operation === ChangeOperation::Delete ? 'NULL' : $this->jsonObject($connection, $identity, $rowPrefix);
    $oldKey = $operation === ChangeOperation::Insert ? 'NULL' : $this->jsonObject($connection, $identity, 'OLD');
    $timing = strtoupper($operation->value);
    return 'CREATE TRIGGER ' . SqlIdentifier::quote($connection, $triggerName)
      . ' AFTER ' . $timing
      . ' ON ' . SqlIdentifier::quote($connection, $table->name)
      . ' FOR EACH ROW INSERT INTO ' . SqlIdentifier::quote($connection, CaptureSchema::LOG_TABLE)
      . ' (table_name, operation, identity_kind, key_json, old_key_json) VALUES ('
      . $this->literal($table->name) . ', '
      . $this->literal($operation->value) . ', '
      . $this->literal($identity->kind->value) . ', '
      . $key . ', ' . $oldKey . ')';
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
    return 'JSON_OBJECT(' . implode(', ', $pairs) . ')';
  }

  private function logTableExists(Connection $connection): bool {
    $schema = (string) ($connection->getConnectionOptions()['database'] ?? '');
    $statement = $connection->query(
      'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name',
      [':schema' => $schema, ':name' => CaptureSchema::LOG_TABLE],
    );
    return $statement !== NULL && (int) $statement->fetchField() === 1;
  }

  private function triggerExists(Connection $connection, string $name): bool {
    $schema = (string) ($connection->getConnectionOptions()['database'] ?? '');
    $statement = $connection->query(
      'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = :schema AND TRIGGER_NAME = :name',
      [':schema' => $schema, ':name' => $name],
    );
    return $statement !== NULL && (int) $statement->fetchField() === 1;
  }

  /**
   * @return list<string>
   */
  private function triggerNames(Connection $connection): array {
    $schema = (string) ($connection->getConnectionOptions()['database'] ?? '');
    $statement = $connection->query(
      'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = :schema ORDER BY TRIGGER_NAME',
      [':schema' => $schema],
    );
    if ($statement === NULL) {
      return [];
    }
    $names = [];
    foreach ($statement->fetchAll(FetchAs::Associative) as $row) {
      $name = (string) $row['TRIGGER_NAME'];
      if (str_starts_with($name, CaptureSchema::TRIGGER_PREFIX)) {
        $names[] = $name;
      }
    }
    return $names;
  }

  /**
   * @return array{0: int, 1: int|null, 2: int|null}
   */
  private function backlog(Connection $connection): array {
    $statement = $connection->query(
      'SELECT COUNT(*) AS pending, MIN(sequence) AS oldest, MAX(sequence) AS newest FROM '
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
   * @param array<string, mixed> $row
   */
  private function record(array $row): ChangeRecord {
    return new ChangeRecord(
      (int) $row['sequence'],
      (string) $row['table_name'],
      ChangeOperation::from((string) $row['operation']),
      ChangeIdentityKind::from((string) $row['identity_kind']),
      $this->decodeKey($row['key_json']),
      $this->decodeKey($row['old_key_json']),
      (string) $row['captured_at'],
    );
  }

  /**
   * @return array<string, int|string|null>|null
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

  private function assertConnection(Connection $connection): void {
    if (strtolower($connection->driver()) !== 'mysql') {
      throw new \InvalidArgumentException('MySQL-family capture requires Drupal’s MySQL driver.');
    }
  }

  private function assertLimit(int $limit): void {
    if ($limit < 1 || $limit > 5000) {
      throw new \InvalidArgumentException('Capture batch limit must be between 1 and 5000.');
    }
  }

}
