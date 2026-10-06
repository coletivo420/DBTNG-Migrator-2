<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Destination\Mysql;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\dbtng_migrator\Contract\DestinationStateInspectorInterface;
use Drupal\dbtng_migrator\Model\DestinationState;
use Drupal\dbtng_migrator\Model\DestinationStateInspection;

/**
 * Counts all user-defined objects in the configured MySQL-family schema. */
final class MysqlDestinationStateInspector implements DestinationStateInspectorInterface {

  public function supports(Connection $connection): bool {
    return strtolower($connection->driver()) === 'mysql';
  }

  public function inspect(Connection $connection): DestinationStateInspection {
    if (!$this->supports($connection)) {
      throw new \InvalidArgumentException('MySQL destination inspection requires Drupal’s MySQL driver.');
    }
    $schema = (string) ($connection->getConnectionOptions()['database'] ?? '');
    if ($schema === '') {
      throw new \RuntimeException('The MySQL destination does not identify a database schema.');
    }
    $reasons = [];
    foreach ($this->rows($connection, 'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema ORDER BY TABLE_NAME', [':schema' => $schema]) as $row) {
      $reasons[] = strtolower((string) $row['TABLE_TYPE']) . ': ' . (string) $row['TABLE_NAME'];
    }
    foreach ($this->rows($connection, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = :schema ORDER BY TRIGGER_NAME', [':schema' => $schema]) as $row) {
      $reasons[] = 'trigger: ' . (string) $row['TRIGGER_NAME'];
    }
    foreach ($this->rows($connection, 'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = :schema ORDER BY ROUTINE_NAME', [':schema' => $schema]) as $row) {
      $reasons[] = strtolower((string) $row['ROUTINE_TYPE']) . ': ' . (string) $row['ROUTINE_NAME'];
    }
    foreach ($this->rows($connection, 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = :schema ORDER BY EVENT_NAME', [':schema' => $schema]) as $row) {
      $reasons[] = 'event: ' . (string) $row['EVENT_NAME'];
    }

    return new DestinationStateInspection(
      $reasons === [] ? DestinationState::Empty : DestinationState::NonEmpty,
      $reasons,
    );
  }

  /**
   * Reads user-defined objects from the target schema.
   *
   * @param array<string, mixed> $arguments
   *   Query placeholder values.
   *
   * @return list<array<string, mixed>>
   *   Inventory rows.
   */
  private function rows(Connection $connection, string $sql, array $arguments = []): array {
    $statement = $connection->query($sql, $arguments);
    if (!$statement instanceof StatementInterface) {
      throw new \RuntimeException('The MySQL destination did not return an inventory result set.');
    }
    return array_values($statement->fetchAll(FetchAs::Associative));
  }

}
