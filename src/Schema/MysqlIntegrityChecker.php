<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Schema;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\DatabaseInventory;

/**
 * Runs native read-only table integrity checks on a MySQL-family database. */
final class MysqlIntegrityChecker {

  public function validate(Connection $connection, DatabaseInventory $inventory): void {
    if (strtolower($connection->driver()) !== 'mysql') {
      throw new \InvalidArgumentException('MysqlIntegrityChecker requires a MySQL-family connection.');
    }
    foreach ($inventory->tables as $table) {
      $statement = $connection->query('CHECK TABLE ' . SqlIdentifier::quote($connection, $table->name));
      if (!$statement instanceof StatementInterface) {
        throw new DbtngException(sprintf('MySQL CHECK TABLE returned no status for "%s".', $table->name));
      }
      $results = $statement->fetchAll(FetchAs::Associative);
      if ($results === []) {
        throw new DbtngException(sprintf('MySQL CHECK TABLE returned no rows for "%s".', $table->name));
      }
      foreach ($results as $result) {
        if (strtolower((string) ($result['Msg_type'] ?? '')) === 'error'
          || !in_array(strtoupper((string) ($result['Msg_text'] ?? '')), ['OK', 'TABLE IS ALREADY UP TO DATE'], TRUE)) {
          throw new DbtngException(sprintf('MySQL CHECK TABLE failed for "%s".', $table->name));
        }
      }
    }
  }

}
