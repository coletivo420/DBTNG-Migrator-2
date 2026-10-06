<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Schema;

use Drupal\dbtng_migrator\Contract\PortabilityAnalyzerInterface;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\PortabilityIssue;
use Drupal\dbtng_migrator\Model\PortabilityReport;

/**
 * Conservative initial analyzer; unproven physical semantics block strict use. */
final class PortabilityAnalyzer implements PortabilityAnalyzerInterface {

  public function analyze(DatabaseInventory $inventory): PortabilityReport {
    $issues = [];
    foreach ($inventory->tables as $table) {
      foreach ($table->columns as $column) {
        $type = strtolower($column->nativeType);
        $baseType = strtolower((string) preg_replace('/\s*\(.*/', '', $type));
        if (in_array($baseType, ['enum', 'set'], TRUE)) {
          $issues[] = new PortabilityIssue('unsupported_type', sprintf('Native %s values have no strict SQLite mapping.', $baseType), 'error', $table->name, $column->name);
        }
        elseif (in_array($baseType, [
          'geometry', 'point', 'linestring', 'polygon', 'multipoint',
          'multilinestring', 'multipolygon', 'geometrycollection',
        ], TRUE)) {
          $issues[] = new PortabilityIssue(
            'spatial_type',
            'Spatial values require a spatial extension and explicit mapping.',
            'error',
            $table->name,
            $column->name,
          );
        }
        elseif ($column->portableType === 'unknown') {
          $issues[] = new PortabilityIssue('unknown_type', sprintf('Native type "%s" has no declared logical mapping.', $column->nativeType), 'error', $table->name, $column->name);
        }
        elseif ($column->portableType === 'numeric' && ($column->precision === NULL || $column->scale === NULL)) {
          $issues[] = new PortabilityIssue('numeric_mapping_incomplete', 'Numeric portability requires explicit precision and scale.', 'error', $table->name, $column->name);
        }
        if ($column->generated) {
          $issues[] = new PortabilityIssue('generated_column', 'Generated columns require a proven target expression mapping.', 'error', $table->name, $column->name);
        }
        if ($column->unsigned) {
          $issues[] = new PortabilityIssue('unsigned_semantics', 'Unsigned range semantics need validation on the target engine.', 'warning', $table->name, $column->name);
        }
        if ($column->collation !== NULL) {
          $issues[] = new PortabilityIssue('column_collation', 'Column collation semantics differ between engines and require review.', 'warning', $table->name, $column->name);
        }
        if ($this->isBackendExpression($column->default)) {
          $issues[] = new PortabilityIssue('backend_default_expression', 'Backend-specific default expression has no proven equivalent.', 'error', $table->name, $column->name);
        }
      }
      if ($table->collation !== NULL) {
        $issues[] = new PortabilityIssue('table_collation', 'Table collation semantics require review on the target engine.', 'warning', $table->name);
      }
      foreach ($table->indexDefinitions as $index) {
        if ($index->functional) {
          $issues[] = new PortabilityIssue('functional_index', 'Functional/expression indexes have no proven portable mapping.', 'error', $table->name, NULL, $index->name);
        }
        if (str_contains(strtolower((string) $index->type), 'fulltext')) {
          $issues[] = new PortabilityIssue('fulltext_index', 'FULLTEXT indexes have no equivalent target semantics.', 'error', $table->name, NULL, $index->name);
        }
        if (str_contains(strtolower((string) $index->type), 'spatial')) {
          $issues[] = new PortabilityIssue('spatial_index', 'Spatial indexes require a spatial extension and explicit target mapping.', 'error', $table->name, NULL, $index->name);
        }
        if ($index->partial) {
          $issues[] = new PortabilityIssue('partial_index', 'Partial index predicates require target-specific review.', 'warning', $table->name, NULL, $index->name);
        }
        foreach ($index->columns as $indexColumn) {
          if ($indexColumn->prefixLength !== NULL) {
            $issues[] = new PortabilityIssue('prefix_index', 'Index prefix length semantics require target-specific review.', 'warning', $table->name, $indexColumn->name, $index->name);
          }
        }
      }
    }
    foreach ($inventory->objects as $object) {
      $issues[] = new PortabilityIssue(
        'unsupported_schema_object',
        sprintf('User-defined %s "%s" is inventoried but not migrated.', $object->type, $object->name),
        'error',
        $object->table,
        NULL,
        $object->name,
      );
    }
    return new PortabilityReport($issues);
  }

  private function isBackendExpression(mixed $default): bool {
    if (!is_string($default)) {
      return FALSE;
    }
    return preg_match('/\b(?:CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME|NOW|UUID)\b|\w+\s*\(/i', $default) === 1;
  }

}
