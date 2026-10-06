<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Schema;

use Drupal\dbtng_migrator\Model\DatabaseInventory;

/**
 * Creates a stable digest of normalized physical schema metadata. */
final class SchemaFingerprint {

  public function calculate(DatabaseInventory $inventory): string {
    $tables = [];
    foreach ($inventory->tables as $table) {
      $columns = [];
      foreach ($table->columns as $column) {
        $columns[] = [
          $column->name,
          $column->portableType,
          $column->nullable,
          $this->normalizeDefault($column->default),
          $column->unsigned,
          $column->length,
          $column->precision,
          $column->scale,
          $column->autoIncrement,
          $column->generated,
          $column->generatedExpression,
          $column->hidden,
        ];
      }
      $indexes = [];
      foreach ($table->indexDefinitions as $index) {
        $parts = [];
        foreach ($index->columns as $part) {
          $parts[] = [$part->name, $part->prefixLength, $part->expression, $part->descending];
        }
        $indexes[] = [
          $index->unique,
          $index->primary,
          $index->type,
          $index->partial,
          $index->predicate,
          $index->functional,
          $parts,
        ];
      }
      $foreignKeys = [];
      foreach ($table->foreignKeys as $foreignKey) {
        $foreignKeys[] = [
          $foreignKey->columns,
          $foreignKey->referencedTable,
          $foreignKey->referencedColumns,
          strtoupper((string) ($foreignKey->onUpdate ?? '')),
          strtoupper((string) ($foreignKey->onDelete ?? '')),
        ];
      }
      $tables[$table->name] = [
        'columns' => $columns,
        'primary_key' => $table->primaryKey,
        'indexes' => $indexes,
        'foreign_keys' => $foreignKeys,
        'flags' => $table->flags,
      ];
    }
    ksort($tables, SORT_STRING);
    return hash('sha256', json_encode($tables, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
  }

  private function normalizeDefault(mixed $default): mixed {
    if (is_string($default)) {
      return trim($default);
    }
    return $default;
  }

}
