<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Import;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Exception\PortabilityException;
use Drupal\dbtng_migrator\Contract\FailureInjectorInterface;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\ReplicationDecision;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\TableNameMap;
use Drupal\dbtng_migrator\Policy\CleanReplicationPolicy;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;

/**
 * Transfers one source cursor at a time using configured row and byte bounds. */
final class RowTransfer {

  public function __construct(
    private readonly CleanReplicationPolicy $cleanPolicy,
    private readonly FailureInjectorInterface $failures,
  ) {}

  /**
   * Streams all selected table rows into the destination in bounded batches.
   *
   * @return array{rows: int, bytes: int, batches: int}
   *   Total transferred data and measured serialized value bytes.
   */
  public function transfer(
    Connection $source,
    Connection $destination,
    DatabaseInventory $inventory,
    ReplicationProfile $profile,
    int $batchRows,
    int $batchBytes,
    ?TableNameMap $tableNames = NULL,
  ): array {
    if ($batchRows < 1 || $batchBytes < 1) {
      throw new \InvalidArgumentException('Import batch limits must be positive.');
    }
    $destinationEngine = strtolower($destination->driver()) === 'mysql' ? DatabaseEngine::MysqlFamily : DatabaseEngine::Sqlite;
    if ($destinationEngine === DatabaseEngine::Sqlite) {
      $destination->query('PRAGMA foreign_keys = OFF');
    }
    $totalRows = 0;
    $totalBytes = 0;
    $batchCount = 0;
    $previousSqlMode = $destinationEngine === DatabaseEngine::MysqlFamily ? $this->enableZeroAutoValueInsert($destination) : NULL;
    try {
      foreach ($inventory->tables as $table) {
        if ($profile === ReplicationProfile::Clean && $this->cleanPolicy->tableDecision($table) === ReplicationDecision::SchemaOnly) {
          continue;
        }
        $columns = array_values(array_filter($table->columns, static fn ($column): bool => !$column->hidden));
        if ($columns === []) {
          throw new PortabilityException(sprintf('Table "%s" has no visible columns to transfer.', $table->name));
        }
        $columnNames = array_map(static fn ($column): string => $column->name, $columns);
        $select = 'SELECT ' . implode(', ', array_map(static fn (string $name): string => SqlIdentifier::quote($source, $name), $columnNames))
        . ' FROM ' . SqlIdentifier::quote($source, $table->name);
        $rows = $source->query($select);
        if (!$rows instanceof StatementInterface) {
          throw new DbtngException(sprintf('Source cursor could not be opened for table "%s".', $table->name));
        }
        $destinationTable = $tableNames?->destination($table->name) ?? $table->name;
        $insert = 'INSERT INTO ' . SqlIdentifier::quote($destination, $destinationTable)
        . ' (' . implode(', ', array_map(static fn (string $name): string => SqlIdentifier::quote($destination, $name), $columnNames)) . ')'
        . ' VALUES (' . implode(', ', array_map(static fn (int $index): string => ':dbtng_' . $index, array_keys($columnNames))) . ')';
        $transaction = NULL;
        $inBatch = 0;
        $batchSize = 0;
        while (($row = $rows->fetch(FetchAs::Associative)) !== FALSE) {
          if (!is_array($row)) {
            throw new DbtngException(sprintf('Source cursor returned an invalid row for table "%s".', $table->name));
          }
          $parameters = [];
          $rowBytes = 0;
          foreach ($columns as $index => $column) {
            $value = $row[$column->name] ?? NULL;
            $this->assertValueFits($value, $column->unsigned, $column->portableType, $destinationEngine, $table->name, $column->name);
            $parameters[':dbtng_' . $index] = $value;
            if (is_string($value)) {
              $rowBytes += strlen($value);
            }
            elseif ($value !== NULL) {
              $rowBytes += strlen((string) $value);
            }
          }
          if ($transaction === NULL) {
            $transaction = $destination->startTransaction();
            if ($destinationEngine === DatabaseEngine::Sqlite) {
              $destination->query('PRAGMA defer_foreign_keys = ON');
            }
          }
          try {
            $destination->query($insert, $parameters);
          }
          catch (\Throwable $exception) {
            unset($transaction);
            $driverCode = 'unknown';
            for ($cause = $exception; $cause !== NULL; $cause = $cause->getPrevious()) {
              if ($cause instanceof \PDOException && isset($cause->errorInfo[1])) {
                $driverCode = (string) $cause->errorInfo[1];
                break;
              }
            }
            throw new DbtngException(sprintf('Row import failed in table "%s" (driver code %s); the standby is not initialized.', $table->name, $driverCode), 0, $exception);
          }
          $totalRows++;
          $totalBytes += $rowBytes;
          $inBatch++;
          $batchSize += $rowBytes;
          if ($inBatch >= $batchRows || $batchSize >= $batchBytes) {
            unset($transaction);
            $transaction = NULL;
            $inBatch = 0;
            $batchSize = 0;
            $batchCount++;
            $this->failures->hit('mid_row_transfer', ['table' => $table->name, 'batch' => $batchCount]);
          }
        }
        if ($inBatch > 0) {
          $batchCount++;
          $this->failures->hit('mid_row_transfer', ['table' => $table->name, 'batch' => $batchCount]);
        }
        unset($transaction);
      }
      if ($destinationEngine === DatabaseEngine::Sqlite) {
        $destination->query('PRAGMA foreign_keys = ON');
      }
      return ['rows' => $totalRows, 'bytes' => $totalBytes, 'batches' => $batchCount];
    }
    finally {
      if ($previousSqlMode !== NULL) {
        $destination->query('SET SESSION sql_mode = :mode', [':mode' => $previousSqlMode]);
      }
    }
  }

  private function enableZeroAutoValueInsert(Connection $destination): string {
    $statement = $destination->query('SELECT @@SESSION.sql_mode');
    if (!$statement instanceof StatementInterface) {
      throw new DbtngException('Unable to read the MySQL standby session mode.');
    }
    $mode = (string) $statement->fetchField();
    $modes = array_map('strtoupper', array_filter(explode(',', $mode)));
    if (!in_array('NO_AUTO_VALUE_ON_ZERO', $modes, TRUE)) {
      $modes[] = 'NO_AUTO_VALUE_ON_ZERO';
      $destination->query('SET SESSION sql_mode = :mode', [':mode' => implode(',', $modes)]);
    }
    return $mode;
  }

  private function assertValueFits(mixed $value, bool $unsigned, string $type, DatabaseEngine $destination, string $table, string $column): void {
    if ($value === NULL || !$unsigned || $type !== 'integer' || $destination !== DatabaseEngine::Sqlite) {
      return;
    }
    $number = (string) $value;
    if (preg_match('/^\d+$/D', $number) !== 1) {
      return;
    }
    $normalized = ltrim($number, '0') ?: '0';
    $maximum = '9223372036854775807';
    if (strlen($normalized) > strlen($maximum) || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
      throw new PortabilityException(sprintf('Unsigned integer value in %s.%s exceeds SQLite signed INTEGER capacity.', $table, $column));
    }
  }

}
