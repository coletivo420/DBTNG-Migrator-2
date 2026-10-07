<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Model\TableDefinition;

/**
 * Applies one row's authoritative current state on a standby connection. */
interface StandbyChangeApplierInterface {

  /**
   * Insert or update a row using its primary key.
   *
   * @param array<string, mixed> $row */
  public function upsert(Connection $standby, TableDefinition $table, array $row): void;

  /**
   * Insert one row when rebuilding a dirty table.
   *
   * @param array<string, mixed> $row */
  public function insertRow(Connection $standby, TableDefinition $table, array $row): void;

  /**
   * Delete a row by its complete primary key.
   *
   * @param array<string, int|string|null> $key */
  public function delete(Connection $standby, TableDefinition $table, array $key): void;

  public function clearTable(Connection $standby, TableDefinition $table): void;

}
