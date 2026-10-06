<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Schema;

use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Exception\PortabilityException;
use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\ForeignKeyDefinition;
use Drupal\dbtng_migrator\Model\IndexColumnDefinition;
use Drupal\dbtng_migrator\Model\TableNameMap;

/**
 * Builds a MySQL-family schema from DBTNG's normalized physical model. */
final class MysqlSchemaBuilder {

  public function __construct(private readonly ImportTypeMapper $types) {}

  public function createTables(Connection $destination, DatabaseInventory $inventory, ?TableNameMap $tableNames = NULL): void {
    if (strtolower($destination->driver()) !== 'mysql') {
      throw new \InvalidArgumentException('MysqlSchemaBuilder requires a MySQL-family destination connection.');
    }
    foreach ($inventory->tables as $table) {
      $definitions = [];
      $singleAutoPrimary = count($table->primaryKey) === 1 && (bool) ($table->flags['autoincrement'] ?? FALSE);
      foreach ($table->columns as $column) {
        if ($column->hidden) {
          continue;
        }
        $definitions[] = $this->columnSql($destination, $column, $singleAutoPrimary && $table->primaryKey[0] === $column->name);
      }
      if ($table->primaryKey !== []) {
        $definitions[] = 'PRIMARY KEY (' . $this->columnList($destination, $table->primaryKey) . ')';
      }
      if ($definitions === []) {
        throw new PortabilityException(sprintf('Table "%s" has no importable columns.', $table->name));
      }
      try {
        $destinationName = $tableNames?->destination($table->name) ?? $table->name;
        if (strlen($destinationName) > 64) {
          throw new PortabilityException(sprintf('Candidate table name for "%s" exceeds the MySQL identifier limit.', $table->name));
        }
        $destination->query('CREATE TABLE ' . SqlIdentifier::quote($destination, $destinationName) . ' (' . implode(', ', $definitions) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
      }
      catch (\Throwable $exception) {
        $driverCode = 'unknown';
        for ($cause = $exception; $cause !== NULL; $cause = $cause->getPrevious()) {
          if ($cause instanceof \PDOException && isset($cause->errorInfo[1])) {
            $driverCode = (string) $cause->errorInfo[1];
            break;
          }
        }
        throw new DbtngException(
          sprintf('MySQL schema creation failed for table "%s" (driver code %s).', $table->name, $driverCode),
          0,
          $exception,
        );
      }
    }
  }

  public function createIndexesAndConstraints(Connection $destination, DatabaseInventory $inventory, ?TableNameMap $tableNames = NULL, ?string $constraintNamespace = NULL): int {
    $warnings = 0;
    foreach ($inventory->tables as $table) {
      foreach ($table->indexDefinitions as $index) {
        if ($index->primary || $index->columns === []) {
          continue;
        }
        if ($index->functional || $index->partial) {
          throw new PortabilityException(sprintf('Index "%s" on table "%s" requires unimplemented MySQL target semantics.', $index->name, $table->name));
        }
        $mappedColumns = $this->fitIndexColumns($table->columns, $index->columns, $index->unique, $index->name, $table->name);
        $warnings += $mappedColumns['adjusted'] ? 1 : 0;
        $columns = [];
        foreach ($mappedColumns['columns'] as $column) {
          if ($column->name === NULL) {
            throw new PortabilityException(sprintf('Index "%s" contains an expression column.', $index->name));
          }
          $entry = SqlIdentifier::quote($destination, $column->name);
          if ($column->prefixLength !== NULL) {
            $entry .= '(' . $column->prefixLength . ')';
          }
          $columns[] = $entry . ($column->descending ? ' DESC' : ' ASC');
        }
        $kind = $index->unique ? 'UNIQUE INDEX' : 'INDEX';
        $name = 'dbtng_' . substr(hash('sha256', $table->name . "\0" . $index->name), 0, 24);
        try {
          $destinationName = $tableNames?->destination($table->name) ?? $table->name;
          $destination->query('ALTER TABLE ' . SqlIdentifier::quote($destination, $destinationName) . ' ADD ' . $kind . ' ' . SqlIdentifier::quote($destination, $name) . ' (' . implode(', ', $columns) . ')');
        }
        catch (\Throwable $exception) {
          throw $this->schemaOperationFailure('index', $table->name . '.' . $index->name, $exception);
        }
      }
      foreach ($table->foreignKeys as $foreignKey) {
        try {
          $destinationName = $tableNames?->destination($table->name) ?? $table->name;
          $destination->query('ALTER TABLE ' . SqlIdentifier::quote($destination, $destinationName) . ' ADD ' . $this->foreignKeySql($destination, $foreignKey, $tableNames, $constraintNamespace));
        }
        catch (\Throwable $exception) {
          throw $this->schemaOperationFailure('foreign key', $table->name . '.' . $foreignKey->name, $exception);
        }
      }
    }
    return $warnings;
  }

  /**
   * Bounds MySQL index keys while preserving unique-index semantics.
   *
   * @param list<ColumnDefinition> $tableColumns
   *   Physical table columns.
   * @param list<IndexColumnDefinition> $indexColumns
   *   Ordered index columns.
   *
   * @return array{columns: list<IndexColumnDefinition>, adjusted: bool}
   *   Mapped columns and whether a non-unique prefix was required.
   */
  private function fitIndexColumns(array $tableColumns, array $indexColumns, bool $unique, string $indexName, string $tableName): array {
    $byName = [];
    foreach ($tableColumns as $column) {
      $byName[$column->name] = $column;
    }
    $sizes = [];
    $total = 0;
    foreach ($indexColumns as $index => $part) {
      if ($part->name === NULL || !isset($byName[$part->name])) {
        throw new PortabilityException(sprintf('Index "%s" on table "%s" has an unresolved column.', $indexName, $tableName));
      }
      $column = $byName[$part->name];
      $size = match ($column->portableType) {
        'integer', 'float' => 8,
        'numeric' => max(8, (int) ($column->precision ?? 32)),
        'date' => 4,
        'time', 'datetime', 'timestamp' => 8,
        'varchar' => max(1, (int) ($part->prefixLength ?? $column->length ?? 255)) * 4,
        'text', 'blob' => $part->prefixLength === NULL ? 65535 : max(1, $part->prefixLength) * 4,
        default => 65535,
      };
      $sizes[$index] = $size;
      $total += $size;
    }
    if ($total <= 3000) {
      return ['columns' => $indexColumns, 'adjusted' => FALSE];
    }
    if ($unique) {
      throw new PortabilityException(sprintf('Unique index "%s" on table "%s" exceeds the MySQL key limit and cannot be shortened safely.', $indexName, $tableName));
    }

    $mapped = $indexColumns;
    for ($position = count($mapped) - 1; $position >= 0 && $total > 3000; $position--) {
      $part = $mapped[$position];
      $column = $part->name === NULL ? NULL : ($byName[$part->name] ?? NULL);
      if ($column === NULL || !in_array($column->portableType, ['varchar', 'text', 'blob'], TRUE)) {
        continue;
      }
      $currentBytes = $sizes[$position];
      $allowedCharacters = intdiv(max(0, 3000 - ($total - $currentBytes)), 4);
      if ($allowedCharacters < 1) {
        continue;
      }
      $originalCharacters = $part->prefixLength ?? ($column->portableType === 'varchar' ? ($column->length ?? 255) : 65535);
      $prefixLength = min($originalCharacters, $allowedCharacters);
      if ($prefixLength >= $originalCharacters) {
        continue;
      }
      $mapped[$position] = new IndexColumnDefinition($part->name, $part->position, $prefixLength, $part->expression, $part->descending);
      $total += ($prefixLength * 4) - $currentBytes;
      $sizes[$position] = $prefixLength * 4;
    }
    if ($total > 3000) {
      throw new PortabilityException(sprintf('Index "%s" on table "%s" cannot fit the MySQL key limit.', $indexName, $tableName));
    }
    return ['columns' => array_values($mapped), 'adjusted' => TRUE];
  }

  private function columnSql(Connection $destination, ColumnDefinition $column, bool $autoIncrementFromTable): string {
    $sql = SqlIdentifier::quote($destination, $column->name) . ' ' . $this->types->type($column, DatabaseEngine::MysqlFamily);
    if (!$column->nullable) {
      $sql .= ' NOT NULL';
    }
    if ($column->autoIncrement || $autoIncrementFromTable) {
      $sql .= ' AUTO_INCREMENT';
    }
    $default = $this->types->defaultValue($column);
    if ($default !== NULL) {
      $sql .= ' DEFAULT ' . $this->literal($destination, $default);
    }
    return $sql;
  }

  private function foreignKeySql(Connection $destination, ForeignKeyDefinition $foreignKey, ?TableNameMap $tableNames, ?string $constraintNamespace): string {
    $constraintName = 'dbtng_' . substr(hash('sha256', ($constraintNamespace ?? '') . "\0" . $foreignKey->name), 0, 24);
    $referencedTable = $tableNames?->destination($foreignKey->referencedTable) ?? $foreignKey->referencedTable;
    $sql = 'CONSTRAINT ' . SqlIdentifier::quote($destination, $constraintName)
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

  private function literal(Connection $connection, mixed $value): string {
    if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1)) {
      return (string) $value;
    }
    if (!is_string($value)) {
      throw new PortabilityException('A column default cannot be represented as a MySQL literal.');
    }
    $quoted = $connection->quote($value);
    if ($quoted === FALSE) {
      throw new PortabilityException('A string column default could not be quoted safely for the MySQL-family target.');
    }
    return $quoted;
  }

  private function schemaOperationFailure(string $kind, string $name, \Throwable $exception): DbtngException {
    $driverCode = 'unknown';
    for ($cause = $exception; $cause !== NULL; $cause = $cause->getPrevious()) {
      if ($cause instanceof \PDOException && isset($cause->errorInfo[1])) {
        $driverCode = (string) $cause->errorInfo[1];
        break;
      }
    }
    return new DbtngException(sprintf('MySQL %s creation failed for "%s" (driver code %s).', $kind, $name, $driverCode), 0, $exception);
  }

}
