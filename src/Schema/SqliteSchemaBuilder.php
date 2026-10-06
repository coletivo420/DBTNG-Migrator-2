<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Schema;

use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Exception\PortabilityException;
use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\ForeignKeyDefinition;
use Drupal\dbtng_migrator\Model\TableNameMap;
use Drupal\dbtng_migrator\Model\IndexDefinition;

/**
 * Builds a SQLite schema from DBTNG's normalized physical model. */
final class SqliteSchemaBuilder {

  public function __construct(private readonly ImportTypeMapper $types) {}

  public function createTables(Connection $destination, DatabaseInventory $inventory, ?TableNameMap $tableNames = NULL): void {
    if (strtolower($destination->driver()) !== 'sqlite') {
      throw new \InvalidArgumentException('SqliteSchemaBuilder requires a SQLite destination connection.');
    }
    foreach ($inventory->tables as $table) {
      $columns = [];
      $autoIncrementColumn = NULL;
      foreach ($table->columns as $candidateColumn) {
        if ($candidateColumn->autoIncrement) {
          $autoIncrementColumn = $candidateColumn->name;
          break;
        }
      }
      $singleAutoPrimary = count($table->primaryKey) === 1
        && ((bool) ($table->flags['autoincrement'] ?? FALSE) || $autoIncrementColumn === $table->primaryKey[0]);
      foreach ($table->columns as $column) {
        if ($column->hidden) {
          continue;
        }
        $isAuto = $singleAutoPrimary && $table->primaryKey[0] === $column->name;
        $columns[] = $this->columnSql($destination, $column, $isAuto);
      }
      if ($table->primaryKey !== [] && !$singleAutoPrimary) {
        $columns[] = 'PRIMARY KEY (' . $this->columnList($destination, $table->primaryKey) . ')';
      }
      foreach ($table->foreignKeys as $foreignKey) {
        $columns[] = $this->foreignKeySql($destination, $foreignKey, $tableNames);
      }
      if ($columns === []) {
        throw new PortabilityException(sprintf('Table "%s" has no importable columns.', $table->name));
      }
      $destinationName = $tableNames?->destination($table->name) ?? $table->name;
      $destination->query('CREATE TABLE ' . SqlIdentifier::quote($destination, $destinationName) . ' (' . implode(', ', $columns) . ')');
    }
  }

  public function createIndexes(Connection $destination, DatabaseInventory $inventory, ?TableNameMap $tableNames = NULL): void {
    foreach ($inventory->tables as $table) {
      foreach ($table->indexDefinitions as $index) {
        if ($index->primary || $index->columns === []) {
          continue;
        }
        if ($index->functional || $index->partial || ($index->unique && $this->hasPrefix($index))) {
          throw new PortabilityException(sprintf('Index "%s" on table "%s" requires unimplemented target-specific semantics.', $index->name, $table->name));
        }
        $name = 'dbtng_' . substr(hash('sha256', $table->name . "\0" . $index->name), 0, 32);
        $columns = [];
        foreach ($index->columns as $column) {
          if ($column->name === NULL) {
            throw new PortabilityException(sprintf('Index "%s" contains an expression column.', $index->name));
          }
          $columns[] = SqlIdentifier::quote($destination, $column->name) . ($column->descending ? ' DESC' : '');
        }
        $unique = $index->unique ? 'UNIQUE ' : '';
        $destinationName = $tableNames?->destination($table->name) ?? $table->name;
        $destination->query('CREATE ' . $unique . 'INDEX ' . SqlIdentifier::quote($destination, $name) . ' ON ' . SqlIdentifier::quote($destination, $destinationName) . ' (' . implode(', ', $columns) . ')');
      }
    }
  }

  private function columnSql(Connection $destination, ColumnDefinition $column, bool $autoPrimary): string {
    $name = SqlIdentifier::quote($destination, $column->name);
    if ($autoPrimary) {
      return $name . ' INTEGER PRIMARY KEY AUTOINCREMENT';
    }
    $sql = $name . ' ' . $this->types->type($column, DatabaseEngine::Sqlite);
    $collation = strtolower((string) ($column->collation ?? ''));
    if ($collation !== '' && str_ends_with($collation, '_ci')) {
      $sql .= ' COLLATE NOCASE_UTF8';
    }
    elseif ($collation !== '' && str_ends_with($collation, '_bin')) {
      $sql .= ' COLLATE BINARY';
    }
    if (!$column->nullable || $column->name !== '' && $column->generated) {
      $sql .= ' NOT NULL';
    }
    $default = $this->types->defaultValue($column);
    if ($default !== NULL) {
      $sql .= ' DEFAULT ' . $this->literal($default);
    }
    return $sql;
  }

  private function foreignKeySql(Connection $destination, ForeignKeyDefinition $foreignKey, ?TableNameMap $tableNames): string {
    $referencedTable = $tableNames?->destination($foreignKey->referencedTable) ?? $foreignKey->referencedTable;
    $sql = 'CONSTRAINT ' . SqlIdentifier::quote($destination, $foreignKey->name)
      . ' FOREIGN KEY (' . $this->columnList($destination, $foreignKey->columns) . ') REFERENCES '
      . SqlIdentifier::quote($destination, $referencedTable)
      . ' (' . $this->columnList($destination, $foreignKey->referencedColumns) . ')';
    foreach (['onUpdate' => 'ON UPDATE', 'onDelete' => 'ON DELETE'] as $property => $label) {
      $action = strtoupper((string) $foreignKey->{$property});
      if ($action !== '' && in_array($action, ['CASCADE', 'SET NULL', 'SET DEFAULT', 'RESTRICT', 'NO ACTION'], TRUE)) {
        $sql .= ' ' . $label . ' ' . $action;
      }
    }
    return $sql;
  }

  /**
   * Quotes an ordered list of physical columns.
   *
   * @param list<string> $columns
   *   Physical column names.
   */
  private function columnList(Connection $connection, array $columns): string {
    return implode(', ', array_map(static fn (string $name): string => SqlIdentifier::quote($connection, $name), $columns));
  }

  private function literal(mixed $value): string {
    if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1)) {
      return (string) $value;
    }
    if (!is_string($value)) {
      throw new PortabilityException('A column default cannot be represented as a SQLite literal.');
    }
    return "'" . str_replace("'", "''", $value) . "'";
  }

  private function hasPrefix(IndexDefinition $index): bool {
    foreach ($index->columns as $column) {
      if ($column->prefixLength !== NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
