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

/**
 * Builds a MySQL-family schema from DBTNG's normalized physical model. */
final class MysqlSchemaBuilder {

  public function __construct(private readonly ImportTypeMapper $types) {}

  public function createTables(Connection $destination, DatabaseInventory $inventory): void {
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
        $destination->query('CREATE TABLE ' . SqlIdentifier::quote($destination, $table->name) . ' (' . implode(', ', $definitions) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
      }
      catch (\Throwable $exception) {
        $driverCode = $exception instanceof \PDOException && isset($exception->errorInfo[1])
          ? (string) $exception->errorInfo[1]
          : 'unknown';
        throw new DbtngException(
          sprintf('MySQL schema creation failed for table "%s" (driver code %s).', $table->name, $driverCode),
          0,
          $exception,
        );
      }
    }
  }

  public function createIndexesAndConstraints(Connection $destination, DatabaseInventory $inventory): void {
    foreach ($inventory->tables as $table) {
      foreach ($table->indexDefinitions as $index) {
        if ($index->primary || $index->columns === []) {
          continue;
        }
        if ($index->functional || $index->partial) {
          throw new PortabilityException(sprintf('Index "%s" on table "%s" requires unimplemented MySQL target semantics.', $index->name, $table->name));
        }
        $columns = [];
        foreach ($index->columns as $column) {
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
        $destination->query('ALTER TABLE ' . SqlIdentifier::quote($destination, $table->name) . ' ADD ' . $kind . ' ' . SqlIdentifier::quote($destination, $name) . ' (' . implode(', ', $columns) . ')');
      }
      foreach ($table->foreignKeys as $foreignKey) {
        $destination->query('ALTER TABLE ' . SqlIdentifier::quote($destination, $table->name) . ' ADD ' . $this->foreignKeySql($destination, $foreignKey));
      }
    }
  }

  private function columnSql(Connection $destination, ColumnDefinition $column, bool $autoIncrementFromTable): string {
    $sql = SqlIdentifier::quote($destination, $column->name) . ' ' . $this->types->type($column, DatabaseEngine::MysqlFamily);
    if (!$column->nullable) {
      $sql .= ' NOT NULL';
    }
    if ($column->autoIncrement || $autoIncrementFromTable) {
      $sql .= ' AUTO_INCREMENT';
    }
    if ($column->default !== NULL) {
      $sql .= ' DEFAULT ' . $this->literal($column->default);
    }
    return $sql;
  }

  private function foreignKeySql(Connection $destination, ForeignKeyDefinition $foreignKey): string {
    $sql = 'CONSTRAINT ' . SqlIdentifier::quote($destination, 'dbtng_' . substr(hash('sha256', $foreignKey->name), 0, 24))
      . ' FOREIGN KEY (' . $this->columnList($destination, $foreignKey->columns) . ') REFERENCES '
      . SqlIdentifier::quote($destination, $foreignKey->referencedTable)
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
      throw new PortabilityException('A column default cannot be represented as a MySQL literal.');
    }
    return "'" . str_replace("'", "''", $value) . "'";
  }

}
