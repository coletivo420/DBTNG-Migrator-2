<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Destination\Sqlite;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\dbtng_migrator\Contract\DestinationStateInspectorInterface;
use Drupal\dbtng_migrator\Model\DestinationState;
use Drupal\dbtng_migrator\Model\DestinationStateInspection;

/**
 * Ignores SQLite's own catalog while detecting application objects. */
final class SqliteDestinationStateInspector implements DestinationStateInspectorInterface {

  public function supports(Connection $connection): bool {
    return in_array(strtolower($connection->driver()), ['sqlite', 'sqlite3'], TRUE);
  }

  public function inspect(Connection $connection): DestinationStateInspection {
    if (!$this->supports($connection)) {
      throw new \InvalidArgumentException('SQLite destination inspection requires Drupal’s SQLite driver.');
    }
    $reasons = [];
    $statement = $connection->query("SELECT type, name FROM sqlite_schema WHERE substr(name, 1, 7) <> 'sqlite_' ORDER BY type, name");
    if (!$statement instanceof StatementInterface) {
      throw new \RuntimeException('The SQLite destination did not return an inventory result set.');
    }
    foreach ($statement->fetchAll(FetchAs::Associative) as $row) {
      $reasons[] = strtolower((string) $row['type']) . ': ' . (string) $row['name'];
    }
    return new DestinationStateInspection(
      $reasons === [] ? DestinationState::Empty : DestinationState::NonEmpty,
      $reasons,
    );
  }

}
