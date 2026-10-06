<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Import;

use Drupal\dbtng_migrator\Exception\PortabilityException;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\PortabilityReport;

/**
 * Keeps review warnings visible while blocking only unsupported import semantics. */
final class ImportPortabilityGate {

  public function assertImportable(DatabaseInventory $inventory, DatabaseEngine $target, PortabilityReport $report): void {
    if (!$report->isPortable()) {
      $first = current(array_filter($report->issues, static fn ($issue): bool => $issue->severity === 'error'));
      throw new PortabilityException($first === FALSE ? 'Strict portability analysis failed.' : sprintf('Import blocked by %s: %s', $first->code, $first->message));
    }
    foreach ($inventory->tables as $table) {
      foreach ($table->columns as $column) {
        if ($column->hidden || $column->generated) {
          throw new PortabilityException(sprintf('Import blocked by non-transferable generated/hidden column %s.%s.', $table->name, $column->name));
        }
        foreach ([$column->collation, $table->collation] as $collation) {
          if ($target === DatabaseEngine::Sqlite && $collation !== NULL
            && !str_ends_with(strtolower($collation), '_ci')
            && !str_ends_with(strtolower($collation), '_bin')) {
            throw new PortabilityException(sprintf('Import blocked because collation semantics for %s.%s have no SQLite mapping.', $table->name, $column->name));
          }
        }
      }
      foreach ($table->indexDefinitions as $index) {
        if ($target === DatabaseEngine::Sqlite && $index->unique) {
          foreach ($index->columns as $column) {
            if ($column->prefixLength !== NULL) {
              throw new PortabilityException(sprintf('Import blocked because unique prefix index %s.%s cannot be represented in SQLite.', $table->name, $index->name));
            }
          }
        }
        if ($index->partial) {
          throw new PortabilityException(sprintf('Import blocked because partial index %s.%s cannot be represented safely.', $table->name, $index->name));
        }
      }
      if (($table->flags['strict'] ?? FALSE) === TRUE) {
        throw new PortabilityException(sprintf('Import blocked because SQLite STRICT table %s has additional validation semantics.', $table->name));
      }
    }
  }

}
