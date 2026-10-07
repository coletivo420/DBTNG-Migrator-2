<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Contract\StandbyChangeApplierInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;

/**
 * Parameterized MariaDB/MySQL standby upsert and delete operations. */
final class MysqlStandbyChangeApplier implements StandbyChangeApplierInterface {

  public function upsert(Connection $standby, TableDefinition $table, array $row): void {
    $this->assertDriver($standby);
    [$columns, $values] = $this->rowValues($table, $row);
    $assignments = [];
    foreach ($columns as $name) {
      if (!in_array($name, $table->primaryKey, TRUE)) {
        $quotedName = SqlIdentifier::quote($standby, $name);
        $assignments[] = $quotedName . ' = VALUES(' . $quotedName . ')';
      }
    }
    if ($assignments === [] && $table->primaryKey !== []) {
      $primary = SqlIdentifier::quote($standby, $table->primaryKey[0]);
      $assignments[] = $primary . ' = ' . $primary;
    }
    $this->insert($standby, $table, $columns, $values, $assignments);
  }

  public function insertRow(Connection $standby, TableDefinition $table, array $row): void {
    $this->assertDriver($standby);
    [$columns, $values] = $this->rowValues($table, $row);
    $this->insert($standby, $table, $columns, $values, []);
  }

  /**
   * Executes a parameterized insert with optional duplicate-key updates.
   *
   * @param list<string> $columns
   * @param array<string, mixed> $values
   * @param list<string> $assignments
   */
  private function insert(Connection $standby, TableDefinition $table, array $columns, array $values, array $assignments): void {
    $quoted = array_map(static fn (string $name): string => SqlIdentifier::quote($standby, $name), $columns);
    $sql = 'INSERT INTO ' . SqlIdentifier::quote($standby, $table->name)
      . ' (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', array_keys($values)) . ')';
    if ($assignments !== []) {
      $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
    }
    $standby->query($sql, $values);
  }

  public function delete(Connection $standby, TableDefinition $table, array $key): void {
    $this->assertDriver($standby);
    $parameters = [];
    $standby->query(
      'DELETE FROM ' . SqlIdentifier::quote($standby, $table->name) . ' WHERE ' . $this->where($standby, $table, $key, $parameters),
      $parameters,
    );
  }

  public function clearTable(Connection $standby, TableDefinition $table): void {
    $this->assertDriver($standby);
    $standby->query('DELETE FROM ' . SqlIdentifier::quote($standby, $table->name));
  }

  /**
   * Validates and orders values according to the physical table definition.
   *
   * @param array<string, mixed> $row
   *
   * @return array{list<string>, array<string, mixed>}
   */
  private function rowValues(TableDefinition $table, array $row): array {
    $columns = [];
    $parameters = [];
    foreach ($table->columns as $column) {
      if ($column->hidden) {
        continue;
      }
      if (!array_key_exists($column->name, $row)) {
        throw new DbtngException(sprintf('Authoritative row for "%s" lacks column "%s".', $table->name, $column->name));
      }
      $columns[] = $column->name;
      $parameters[':dbtng_value_' . count($columns)] = $row[$column->name];
    }
    if ($columns === []) {
      throw new DbtngException(sprintf('Table "%s" has no insertable columns.', $table->name));
    }
    return [$columns, $parameters];
  }

  /**
   * Builds a parameterized predicate in physical primary-key order.
   *
   * @param array<string, int|string|null> $key
   * @param array<string, int|string|null> $parameters
   */
  private function where(Connection $connection, TableDefinition $table, array $key, array &$parameters): string {
    if ($table->primaryKey === [] || count($key) !== count($table->primaryKey)) {
      throw new DbtngException(sprintf('Table "%s" has no complete primary key for delete.', $table->name));
    }
    $parts = [];
    foreach ($table->primaryKey as $index => $column) {
      if (!array_key_exists($column, $key)) {
        throw new DbtngException(sprintf('Delete key for "%s" lacks "%s".', $table->name, $column));
      }
      $placeholder = ':dbtng_key_' . $index;
      $parts[] = SqlIdentifier::quote($connection, $column) . ' = ' . $placeholder;
      $parameters[$placeholder] = $key[$column];
    }
    return implode(' AND ', $parts);
  }

  private function assertDriver(Connection $connection): void {
    if (strtolower($connection->driver()) !== 'mysql') {
      throw new \InvalidArgumentException('MySQL standby adapter requires the MySQL-family driver.');
    }
  }

}
