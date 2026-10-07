<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Database\StatementInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;

/**
 * Reads authoritative current rows through an explicit primary connection. */
final class PrimaryRowReader {

  /**
   * Reads one current source row by its complete physical primary key.
   *
   * @param array<string, int|string|null> $key
   *
   * @return array<string, mixed>|null
   */
  public function read(Connection $primary, TableDefinition $table, array $key): ?array {
    if ($table->primaryKey === [] || count($key) !== count($table->primaryKey)) {
      throw new DbtngException(sprintf('Table "%s" does not have a complete row-addressable primary key.', $table->name));
    }
    $columns = [];
    foreach ($table->columns as $column) {
      if (!$column->hidden) {
        $columns[] = $column->name;
      }
    }
    if ($columns === []) {
      throw new DbtngException(sprintf('Table "%s" has no readable columns.', $table->name));
    }
    $where = [];
    $parameters = [];
    foreach ($table->primaryKey as $index => $name) {
      if (!array_key_exists($name, $key)) {
        throw new DbtngException(sprintf('Current-state lookup for "%s" is missing key column "%s".', $table->name, $name));
      }
      $placeholder = ':dbtng_key_' . $index;
      $where[] = SqlIdentifier::quote($primary, $name) . ' = ' . $placeholder;
      $parameters[$placeholder] = $key[$name];
    }
    $sql = 'SELECT ' . implode(', ', array_map(static fn (string $name): string => SqlIdentifier::quote($primary, $name), $columns))
      . ' FROM ' . SqlIdentifier::quote($primary, $table->name)
      . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
    $statement = $primary->query($sql, $parameters);
    if (!$statement instanceof StatementInterface) {
      throw new DbtngException(sprintf('Unable to read current primary state for "%s".', $table->name));
    }
    $row = $statement->fetch(FetchAs::Associative);
    return is_array($row) ? $row : NULL;
  }

}
