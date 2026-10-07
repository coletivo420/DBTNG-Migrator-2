<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Database\StatementInterface;
use Drupal\dbtng_migrator\Contract\StandbyChangeApplierInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;

/**
 * Replaces one table's rows in a bounded-memory standby transaction. */
final class TableReconciler {

  /**
   * Replaces rows and verifies the destination count before commit.
   *
   * @return int Number of copied rows.
   */
  public function reconcile(
    Connection $primary,
    Connection $standby,
    TableDefinition $table,
    StandbyChangeApplierInterface $applier,
    bool $schemaOnly = FALSE,
  ): int {
    $columns = array_values(array_filter($table->columns, static fn ($column): bool => !$column->hidden));
    if ($columns === []) {
      throw new DbtngException(sprintf('Cannot reconcile table "%s" without visible columns.', $table->name));
    }
    $names = array_map(static fn ($column): string => $column->name, $columns);
    $statement = NULL;
    $rows = 0;
    $transaction = $standby->startTransaction();
    try {
      $applier->clearTable($standby, $table);
      if (!$schemaOnly) {
        $statement = $primary->query(
          'SELECT ' . implode(', ', array_map(static fn (string $name): string => SqlIdentifier::quote($primary, $name), $names))
          . ' FROM ' . SqlIdentifier::quote($primary, $table->name),
        );
        if (!$statement instanceof StatementInterface) {
          throw new DbtngException(sprintf('Unable to open a bounded cursor for table "%s".', $table->name));
        }
        while (($row = $statement->fetch(FetchAs::Associative)) !== FALSE) {
          if (!is_array($row)) {
            throw new DbtngException(sprintf('Invalid streamed row for table "%s".', $table->name));
          }
          $applier->insertRow($standby, $table, $row);
          $rows++;
        }
      }
      $count = $standby->query('SELECT COUNT(*) FROM ' . SqlIdentifier::quote($standby, $table->name));
      if (!$count instanceof StatementInterface || (int) $count->fetchField() !== $rows) {
        throw new DbtngException(sprintf('Table reconciliation row-count validation failed for "%s".', $table->name));
      }
      unset($transaction);
      return $rows;
    }
    catch (\Throwable $exception) {
      if (is_object($transaction)) {
        $transaction->rollBack();
      }
      throw $exception;
    }
    finally {
      $statement = NULL;
    }
  }

}
