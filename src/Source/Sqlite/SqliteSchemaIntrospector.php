<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Source\Sqlite;

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
 * Inventories SQLite's physical schema and omits SQLite-owned internal objects. */
final class SqliteSchemaIntrospector implements SourceSchemaIntrospectorInterface {

  public function supports(Connection $connection): bool {
    return in_array(strtolower($connection->driver()), ['sqlite', 'sqlite3'], TRUE);
  }

  public function inspect(Connection $connection): DatabaseInventory {
    if (!$this->supports($connection)) {
      throw new \InvalidArgumentException('SqliteSchemaIntrospector requires Drupal’s SQLite driver.');
    }
    $schemaRows = $this->rows($connection, "SELECT type, name, tbl_name, sql FROM sqlite_schema WHERE substr(name, 1, 7) <> 'sqlite_' ORDER BY type, name");
    $tableSql = [];
    $objects = [];
    foreach ($schemaRows as $row) {
      $type = strtolower((string) $row['type']);
      $name = (string) $row['name'];
      if ($type === 'table') {
        $tableSql[$name] = (string) ($row['sql'] ?? '');
      }
      elseif (in_array($type, ['view', 'trigger'], TRUE)) {
        $objects[] = new DatabaseObjectDefinition($type, $name, $row['tbl_name'] === NULL ? NULL : (string) $row['tbl_name']);
      }
    }

    $tables = [];
    foreach ($tableSql as $tableName => $createSql) {
      $quotedName = "'" . str_replace("'", "''", $tableName) . "'";
      $columns = [];
      $primaryColumns = [];
      foreach ($this->rows($connection, "PRAGMA table_xinfo({$quotedName})") as $columnRow) {
        $hiddenValue = (int) $columnRow['hidden'];
        $nativeType = (string) $columnRow['type'];
        $declaredType = strtolower((string) preg_replace('/\s*\(.*/', '', $nativeType));
        $logicalType = PhysicalTypeMapper::logicalType($declaredType);
        if ($logicalType === 'unknown') {
          $logicalType = PhysicalTypeMapper::logicalType($this->sqliteAffinityType($nativeType));
        }
        $length = NULL;
        $precision = NULL;
        $scale = NULL;
        if (preg_match('/\((\d+)(?:\s*,\s*(\d+))?\)/', $nativeType, $typeDimensions) === 1) {
          if ($logicalType === 'varchar') {
            $length = (int) $typeDimensions[1];
          }
          elseif ($logicalType === 'numeric') {
            $precision = (int) $typeDimensions[1];
            $scale = isset($typeDimensions[2]) ? (int) $typeDimensions[2] : 0;
          }
        }
        $column = new ColumnDefinition(
          (string) $columnRow['name'],
          $nativeType,
          $logicalType,
          (int) $columnRow['notnull'] === 0,
          $columnRow['dflt_value'],
          FALSE,
          $length,
          $precision,
          $scale,
          FALSE,
          in_array($hiddenValue, [2, 3], TRUE),
          NULL,
          NULL,
          NULL,
          $hiddenValue > 0,
        );
        $columns[] = $column;
        if ((int) $columnRow['pk'] > 0) {
          $primaryColumns[(int) $columnRow['pk']] = (string) $columnRow['name'];
        }
      }
      ksort($primaryColumns);

      $indexes = [];
      foreach ($this->rows($connection, "PRAGMA index_list({$quotedName})") as $indexRow) {
        $indexName = (string) $indexRow['name'];
        $quotedIndex = "'" . str_replace("'", "''", $indexName) . "'";
        $indexColumns = [];
        foreach ($this->rows($connection, "PRAGMA index_xinfo({$quotedIndex})") as $indexColumn) {
          if ((int) ($indexColumn['key'] ?? 1) !== 1) {
            continue;
          }
          $columnName = $indexColumn['name'] === NULL ? NULL : (string) $indexColumn['name'];
          $indexColumns[] = new IndexColumnDefinition(
            $columnName,
            (int) $indexColumn['seqno'],
            NULL,
            NULL,
            (int) ($indexColumn['desc'] ?? 0) === 1,
          );
        }
        $origin = (string) ($indexRow['origin'] ?? 'c');
        $functional = FALSE;
        foreach ($indexColumns as $indexColumn) {
          $functional = $functional || $indexColumn->name === NULL;
        }
        $indexes[] = new IndexDefinition(
          $indexName,
          (int) $indexRow['unique'] === 1,
          $origin === 'pk',
          $indexColumns,
          'btree',
          (int) ($indexRow['partial'] ?? 0) === 1,
          NULL,
          $functional,
        );
      }

      $foreignGroups = [];
      foreach ($this->rows($connection, "PRAGMA foreign_key_list({$quotedName})") as $foreignRow) {
        $group = (int) $foreignRow['id'];
        $foreignGroups[$group]['name'] = 'fk_' . $tableName . '_' . $group;
        $foreignGroups[$group]['columns'][(int) $foreignRow['seq']] = (string) $foreignRow['from'];
        $foreignGroups[$group]['referenced_table'] = (string) $foreignRow['table'];
        $foreignGroups[$group]['referenced_columns'][(int) $foreignRow['seq']] = (string) $foreignRow['to'];
        $foreignGroups[$group]['on_update'] = (string) $foreignRow['on_update'];
        $foreignGroups[$group]['on_delete'] = (string) $foreignRow['on_delete'];
        $foreignGroups[$group]['match'] = (string) $foreignRow['match'];
      }
      $foreignKeys = [];
      foreach ($foreignGroups as $foreignGroup) {
        ksort($foreignGroup['columns']);
        ksort($foreignGroup['referenced_columns']);
        $foreignKeys[] = new ForeignKeyDefinition(
          $foreignGroup['name'],
          array_values($foreignGroup['columns']),
          $foreignGroup['referenced_table'],
          array_values($foreignGroup['referenced_columns']),
          $foreignGroup['on_update'],
          $foreignGroup['on_delete'],
          $foreignGroup['match'],
        );
      }

      $uniqueKeys = [];
      $normalIndexes = [];
      foreach ($indexes as $index) {
        if ($index->primary) {
          continue;
        }
        $names = array_map(static fn (IndexColumnDefinition $column): string => $column->name ?? '', $index->columns);
        if ($index->unique) {
          $uniqueKeys[$index->name] = $names;
        }
        else {
          $normalIndexes[$index->name] = $names;
        }
      }

      $tables[] = new TableDefinition(
        $tableName,
        $columns,
        array_values($primaryColumns),
        $uniqueKeys,
        $normalIndexes,
        NULL,
        NULL,
        NULL,
        $indexes,
        $foreignKeys,
        [
          'without_rowid' => preg_match('/\bWITHOUT\s+ROWID\b/i', $createSql) === 1,
          'strict' => preg_match('/\)\s*(?:WITHOUT\s+ROWID\s*,?\s*)?STRICT\s*$/i', $createSql) === 1,
          'autoincrement' => preg_match('/\bAUTOINCREMENT\b/i', $createSql) === 1,
        ],
      );
    }

    $version = (string) $this->scalar($connection, 'SELECT sqlite_version()');
    return new DatabaseInventory(DatabaseEngine::Sqlite, $tables, $objects, 'SQLite', $version);
  }

  /**
   * Reads rows returned by an SQLite schema query.
   *
   * @return list<array<string, mixed>>
   *   Schema metadata rows.
   */
  private function rows(Connection $connection, string $sql): array {
    $statement = $connection->query($sql);
    if (!$statement instanceof StatementInterface) {
      throw new \RuntimeException('The SQLite connection did not return schema rows.');
    }
    return array_values($statement->fetchAll(FetchAs::Associative));
  }

  private function scalar(Connection $connection, string $sql): mixed {
    $statement = $connection->query($sql);
    if ($statement === NULL) {
      throw new \RuntimeException('The SQLite connection did not return a version result.');
    }
    return $statement->fetchField();
  }

  private function sqliteAffinityType(string $type): string {
    $type = strtoupper($type);
    if (str_contains($type, 'INT')) {
      return 'integer';
    }
    if (str_contains($type, 'CHAR') || str_contains($type, 'CLOB') || str_contains($type, 'TEXT')) {
      return 'text';
    }
    if (str_contains($type, 'BLOB') || $type === '') {
      return 'blob';
    }
    if (str_contains($type, 'REAL') || str_contains($type, 'FLOA') || str_contains($type, 'DOUB')) {
      return 'float';
    }
    if (str_contains($type, 'NUM') || str_contains($type, 'DEC')) {
      return 'numeric';
    }
    return $type;
  }

}
