<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Destination\Mysql;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\DestinationCleanerInterface;
use Drupal\dbtng_migrator\Destination\DestinationStateInspectionManager;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\DatabaseRole;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DestinationState;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;

/**
 * Removes user objects only from a physically distinct MySQL-family standby. */
final class MysqlDestinationCleaner implements DestinationCleanerInterface {

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly DestinationStateInspectionManager $inspector,
  ) {}

  public function clearStandby(DatabaseTopology $topology): void {
    $resolved = $this->resolver->resolve();
    if ($resolved->topology->standbyEngine !== DatabaseEngine::MysqlFamily
      || $resolved->standby->role !== DatabaseRole::Standby
      || $resolved->standby->engine !== DatabaseEngine::MysqlFamily
      || $resolved->standby->identity === $resolved->primary->identity
      || $resolved->standby->connectionKey === $resolved->primary->connectionKey
      || !$this->sameTopology($topology, $resolved->topology)) {
      throw new DbtngException('MySQL standby clear refused because the target is not a proven, distinct standby.');
    }
    $connection = $resolved->standby->connection;
    $schema = (string) ($connection->getConnectionOptions()['database'] ?? '');
    if ($schema === '') {
      throw new DbtngException('MySQL standby clear refused because its database identity is missing.');
    }

    // Names are taken only from information_schema scoped to this connection's
    // database. Identifiers are escaped by Drupal's active MySQL driver.
    $tableSql = 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = :type';
    $views = $this->column($connection, $tableSql, [':schema' => $schema, ':type' => 'VIEW']);
    $tables = $this->column($connection, $tableSql, [':schema' => $schema, ':type' => 'BASE TABLE']);
    $routines = $this->rows($connection, 'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = :schema', [':schema' => $schema]);
    $events = $this->column($connection, 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = :schema', [':schema' => $schema]);
    $quotedSchema = SqlIdentifier::quote($connection, $schema);

    try {
      foreach ($views as $name) {
        $connection->query('DROP VIEW IF EXISTS ' . $quotedSchema . '.' . SqlIdentifier::quote($connection, $name));
      }
      foreach ($routines as $routine) {
        $type = strtoupper((string) $routine['ROUTINE_TYPE']);
        if (!in_array($type, ['PROCEDURE', 'FUNCTION'], TRUE)) {
          throw new DbtngException('MySQL standby clear encountered an unsupported routine type.');
        }
        $connection->query('DROP ' . $type . ' IF EXISTS ' . $quotedSchema . '.' . SqlIdentifier::quote($connection, (string) $routine['ROUTINE_NAME']));
      }
      foreach ($events as $name) {
        $connection->query('DROP EVENT IF EXISTS ' . $quotedSchema . '.' . SqlIdentifier::quote($connection, $name));
      }
      $connection->query('SET FOREIGN_KEY_CHECKS = 0');
      foreach ($tables as $name) {
        $connection->query('DROP TABLE IF EXISTS ' . $quotedSchema . '.' . SqlIdentifier::quote($connection, $name));
      }
    }
    catch (\Throwable $exception) {
      throw new DbtngException('MySQL standby clear failed; destination state may be partial and must be inspected.', 0, $exception);
    }
    finally {
      try {
        $connection->query('SET FOREIGN_KEY_CHECKS = 1');
      }
      catch (\Throwable) {
        // Continue reporting the original operation failure.
      }
    }

    if ($this->inspector->inspect($connection)->state !== DestinationState::Empty) {
      throw new DbtngException('MySQL standby clear completed but user-defined objects remain.');
    }
  }

  /**
   * Reads the first column from a scoped metadata query.
   *
   * @param array<string, mixed> $arguments
   *   Bound query arguments.
   *
   * @return list<string>
   *   Metadata values.
   */
  private function column(Connection $connection, string $sql, array $arguments): array {
    $values = [];
    foreach ($this->rows($connection, $sql, $arguments) as $row) {
      $values[] = (string) reset($row);
    }
    return $values;
  }

  /**
   * Reads a small metadata result set.
   *
   * @param array<string, mixed> $arguments
   *   Bound query arguments.
   *
   * @return list<array<string, mixed>>
   *   Metadata records.
   */
  private function rows(Connection $connection, string $sql, array $arguments): array {
    $statement = $connection->query($sql, $arguments);
    if (!$statement instanceof StatementInterface) {
      throw new DbtngException('Unable to enumerate MySQL standby objects.');
    }
    return array_values($statement->fetchAll(FetchAs::Associative));
  }

  private function sameTopology(DatabaseTopology $expected, DatabaseTopology $actual): bool {
    return $expected->primaryEngine === $actual->primaryEngine
      && $expected->standbyEngine === $actual->standbyEngine
      && $expected->primaryConnectionKey === $actual->primaryConnectionKey
      && $expected->standbyConnectionKey === $actual->standbyConnectionKey;
  }

}
