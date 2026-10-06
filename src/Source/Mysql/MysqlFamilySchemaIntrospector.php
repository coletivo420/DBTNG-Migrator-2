<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Source\Mysql;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\dbtng_migrator\Contract\SourceSchemaIntrospectorInterface;
use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\DatabaseObjectDefinition;
use Drupal\dbtng_migrator\Model\ForeignKeyDefinition;
use Drupal\dbtng_migrator\Model\IndexColumnDefinition;
use Drupal\dbtng_migrator\Model\IndexDefinition;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Schema\PhysicalTypeMapper;

/**
 * Inventories only physical objects belonging to the configured Drupal schema. */
final class MysqlFamilySchemaIntrospector implements SourceSchemaIntrospectorInterface {

  public function supports(Connection $connection): bool {
    return strtolower($connection->driver()) === 'mysql';
  }

  public function inspect(Connection $connection): DatabaseInventory {
    if (!$this->supports($connection)) {
      throw new \InvalidArgumentException('MysqlFamilySchemaIntrospector requires Drupal’s MySQL driver.');
    }
    $options = $connection->getConnectionOptions();
    $schema = (string) ($options['database'] ?? '');
    if ($schema === '') {
      throw new \RuntimeException('The MySQL connection does not identify a database schema.');
    }

    $tableRows = $this->rows($connection, 'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema ORDER BY TABLE_NAME', [':schema' => $schema]);
    $baseRows = [];
    $objects = [];
    foreach ($tableRows as $row) {
      $name = (string) $row['TABLE_NAME'];
      if (!$this->matchesPrefix($name, $options['prefix'] ?? '')) {
        continue;
      }
      if (strtoupper((string) $row['TABLE_TYPE']) === 'BASE TABLE') {
        $baseRows[$name] = $row;
      }
      elseif (strtoupper((string) $row['TABLE_TYPE']) === 'VIEW') {
        $objects[] = new DatabaseObjectDefinition('view', $name);
      }
    }

    $columns = [];
    foreach ($this->rows($connection, 'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, EXTRA, GENERATION_EXPRESSION, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema ORDER BY TABLE_NAME, ORDINAL_POSITION', [':schema' => $schema]) as $row) {
      $table = (string) $row['TABLE_NAME'];
      if (!isset($baseRows[$table])) {
        continue;
      }
      $native = (string) $row['COLUMN_TYPE'];
      $extra = strtolower((string) $row['EXTRA']);
      $generated = str_contains($extra, 'generated') || (string) $row['GENERATION_EXPRESSION'] !== '';
      $columns[$table][] = new ColumnDefinition(
        (string) $row['COLUMN_NAME'],
        $native,
        PhysicalTypeMapper::logicalType((string) $row['DATA_TYPE']),
        strtoupper((string) $row['IS_NULLABLE']) === 'YES',
        $row['COLUMN_DEFAULT'],
        preg_match('/\bunsigned\b/i', $native) === 1,
        $this->nullableInt($row['CHARACTER_MAXIMUM_LENGTH']),
        $this->nullableInt($row['NUMERIC_PRECISION']),
        $this->nullableInt($row['NUMERIC_SCALE']),
        str_contains($extra, 'auto_increment'),
        $generated,
        $generated ? (string) $row['GENERATION_EXPRESSION'] : NULL,
        $row['CHARACTER_SET_NAME'] === NULL ? NULL : (string) $row['CHARACTER_SET_NAME'],
        $row['COLLATION_NAME'] === NULL ? NULL : (string) $row['COLLATION_NAME'],
      );
    }

    $indexes = [];
    foreach ($this->rows($connection, 'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :schema ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX', [':schema' => $schema]) as $row) {
      $table = (string) $row['TABLE_NAME'];
      if (!isset($baseRows[$table])) {
        continue;
      }
      $name = (string) $row['INDEX_NAME'];
      $indexes[$table][$name]['unique'] = (int) $row['NON_UNIQUE'] === 0;
      $indexes[$table][$name]['primary'] = $name === 'PRIMARY';
      $indexes[$table][$name]['type'] = (string) $row['INDEX_TYPE'];
      $indexes[$table][$name]['columns'][] = new IndexColumnDefinition(
        $row['COLUMN_NAME'] === NULL ? NULL : (string) $row['COLUMN_NAME'],
        (int) $row['SEQ_IN_INDEX'],
        $this->nullableInt($row['SUB_PART']),
        NULL,
      );
      $indexes[$table][$name]['functional'] = $indexes[$table][$name]['functional'] ?? $row['COLUMN_NAME'] === NULL;
    }

    $foreignKeys = [];
    $constraintRows = $this->rows($connection, 'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, k.ORDINAL_POSITION, r.UPDATE_RULE, r.DELETE_RULE, r.MATCH_OPTION FROM information_schema.KEY_COLUMN_USAGE k LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA = :schema AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION', [':schema' => $schema]);
    foreach ($constraintRows as $row) {
      $table = (string) $row['TABLE_NAME'];
      if (!isset($baseRows[$table])) {
        continue;
      }
      $name = (string) $row['CONSTRAINT_NAME'];
      $foreignKeys[$table][$name]['columns'][] = (string) $row['COLUMN_NAME'];
      $foreignKeys[$table][$name]['referenced_columns'][] = (string) $row['REFERENCED_COLUMN_NAME'];
      $foreignKeys[$table][$name]['referenced_table'] = (string) $row['REFERENCED_TABLE_NAME'];
      $foreignKeys[$table][$name]['on_update'] = $row['UPDATE_RULE'] === NULL ? NULL : (string) $row['UPDATE_RULE'];
      $foreignKeys[$table][$name]['on_delete'] = $row['DELETE_RULE'] === NULL ? NULL : (string) $row['DELETE_RULE'];
      $foreignKeys[$table][$name]['match'] = $row['MATCH_OPTION'] === NULL ? NULL : (string) $row['MATCH_OPTION'];
    }

    foreach ($this->rows($connection, 'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = :schema ORDER BY TRIGGER_NAME', [':schema' => $schema]) as $row) {
      $objects[] = new DatabaseObjectDefinition('trigger', (string) $row['TRIGGER_NAME'], (string) $row['EVENT_OBJECT_TABLE']);
    }
    foreach ($this->rows($connection, 'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = :schema ORDER BY ROUTINE_NAME', [':schema' => $schema]) as $row) {
      $objects[] = new DatabaseObjectDefinition(strtolower((string) $row['ROUTINE_TYPE']), (string) $row['ROUTINE_NAME']);
    }
    foreach ($this->rows($connection, 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = :schema ORDER BY EVENT_NAME', [':schema' => $schema]) as $row) {
      $objects[] = new DatabaseObjectDefinition('event', (string) $row['EVENT_NAME']);
    }

    $tables = [];
    foreach ($baseRows as $name => $row) {
      $tableIndexes = [];
      foreach ($indexes[$name] ?? [] as $indexName => $index) {
        $tableIndexes[] = new IndexDefinition(
          $indexName,
          $index['unique'],
          $index['primary'],
          $index['columns'],
          $index['type'],
          FALSE,
          NULL,
          $index['functional'],
        );
      }
      $tableForeignKeys = [];
      foreach ($foreignKeys[$name] ?? [] as $constraintName => $foreignKey) {
        $tableForeignKeys[] = new ForeignKeyDefinition(
          $constraintName,
          $foreignKey['columns'],
          $foreignKey['referenced_table'],
          $foreignKey['referenced_columns'],
          $foreignKey['on_update'],
          $foreignKey['on_delete'],
          $foreignKey['match'],
        );
      }
      $primaryKey = [];
      $uniqueKeys = [];
      $normalIndexes = [];
      foreach ($tableIndexes as $index) {
        $names = array_map(static fn (IndexColumnDefinition $column): string => $column->name ?? '', $index->columns);
        if ($index->primary) {
          $primaryKey = $names;
        }
        elseif ($index->unique) {
          $uniqueKeys[$index->name] = $names;
        }
        else {
          $normalIndexes[$index->name] = $names;
        }
      }
      $tables[] = new TableDefinition(
        $name,
        $columns[$name] ?? [],
        $primaryKey,
        $uniqueKeys,
        $normalIndexes,
        $row['ENGINE'] === NULL ? NULL : (string) $row['ENGINE'],
        $row['TABLE_COLLATION'] === NULL ? NULL : (string) $row['TABLE_COLLATION'],
        $this->nullableInt($row['TABLE_ROWS']),
        $tableIndexes,
        $tableForeignKeys,
      );
    }

    $version = (string) $this->scalar($connection, 'SELECT VERSION()');
    $product = stripos($version, 'mariadb') !== FALSE ? 'MariaDB' : 'MySQL';
    return new DatabaseInventory(DatabaseEngine::MysqlFamily, $tables, $objects, $product, $version);
  }

  /**
   * Fetches a bounded amount of schema metadata rows.
   *
   * @param array<string, mixed> $arguments
   *   Placeholder values.
   *
   * @return list<array<string, mixed>>
   *   Metadata rows.
   */
  private function rows(Connection $connection, string $sql, array $arguments = []): array {
    $statement = $connection->query($sql, $arguments);
    if (!$statement instanceof StatementInterface) {
      throw new \RuntimeException('The database did not return an information_schema result set.');
    }
    return array_values($statement->fetchAll(FetchAs::Associative));
  }

  private function scalar(Connection $connection, string $sql): mixed {
    $statement = $connection->query($sql);
    if ($statement === NULL) {
      throw new \RuntimeException('The database did not return a server version.');
    }
    return $statement->fetchField();
  }

  private function nullableInt(mixed $value): ?int {
    return $value === NULL ? NULL : (int) $value;
  }

  private function matchesPrefix(string $table, mixed $configuredPrefix): bool {
    if (is_string($configuredPrefix)) {
      return $configuredPrefix === '' || str_starts_with($table, $configuredPrefix);
    }
    if (!is_array($configuredPrefix)) {
      return TRUE;
    }
    $prefixes = array_filter($configuredPrefix, 'is_string');
    if (in_array('', $prefixes, TRUE) || $prefixes === []) {
      return TRUE;
    }
    foreach ($prefixes as $prefix) {
      if (str_starts_with($table, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
