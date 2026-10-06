<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Schema;

use Drupal\dbtng_migrator\Exception\PortabilityException;
use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\DatabaseEngine;

/**
 * Explicit target types for the initial cross-engine logical import pair. */
final class ImportTypeMapper {

  public function type(ColumnDefinition $column, DatabaseEngine $target): string {
    if ($column->generated || $column->hidden || $column->portableType === 'unknown') {
      throw new PortabilityException(sprintf('Column "%s" has physical semantics that are not supported for logical import.', $column->name));
    }
    if ($target === DatabaseEngine::Sqlite) {
      return match ($column->portableType) {
        'integer' => 'INTEGER',
        'varchar' => 'VARCHAR(' . max(1, min(65535, $column->length ?? 255)) . ')',
        'text' => 'TEXT',
        'blob' => 'BLOB',
        'float' => 'REAL',
        'numeric' => $this->sqliteNumeric($column),
        'date', 'datetime', 'timestamp', 'time' => 'TEXT',
        default => throw new PortabilityException(sprintf('Column "%s" has no SQLite type mapping.', $column->name)),
      };
    }
    return match ($column->portableType) {
      'integer' => $this->mysqlIntegerType($column),
      'varchar' => 'VARCHAR(' . max(1, min(65535, $column->length ?? 255)) . ')',
      'text' => 'LONGTEXT',
      'blob' => 'LONGBLOB',
      'float' => 'DOUBLE',
      'numeric' => $this->mysqlNumeric($column),
      'date' => 'DATE',
      'datetime' => 'DATETIME',
      'timestamp' => 'TIMESTAMP',
      'time' => 'TIME',
      default => throw new PortabilityException(sprintf('Column "%s" has no MySQL-family type mapping.', $column->name)),
    };
  }

  private function mysqlIntegerType(ColumnDefinition $column): string {
    $type = strtolower((string) preg_replace('/\s*\(.*/', '', $column->nativeType));
    $target = match ($type) {
      'tinyint', 'smallint', 'mediumint', 'bigint' => strtoupper($type),
      'integer' => 'BIGINT',
      default => 'INT',
    };
    return $target . ($column->unsigned ? ' UNSIGNED' : '');
  }

  private function mysqlNumeric(ColumnDefinition $column): string {
    if ($column->precision === NULL || $column->scale === NULL || $column->precision < 1 || $column->scale < 0 || $column->scale > $column->precision) {
      throw new PortabilityException(sprintf('Numeric column "%s" lacks a valid precision and scale.', $column->name));
    }
    return sprintf('DECIMAL(%d,%d)', $column->precision, $column->scale);
  }

  private function sqliteNumeric(ColumnDefinition $column): string {
    if ($column->precision === NULL || $column->scale === NULL || $column->precision > 15) {
      throw new PortabilityException(sprintf('Numeric column "%s" precision cannot be represented safely by SQLite numeric affinity.', $column->name));
    }
    return sprintf('NUMERIC(%d,%d)', $column->precision, $column->scale);
  }

}
