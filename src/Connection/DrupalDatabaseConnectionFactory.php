<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Connection;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\dbtng_migrator\Contract\DatabaseConnectionFactoryInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;

/**
 * Resolves explicit named connections from Drupal's registry.
 *
 * This is the only service that calls Database::getConnection(). It never
 * changes Drupal's active/default connection.
 */
final class DrupalDatabaseConnectionFactory implements DatabaseConnectionFactoryInterface {

  public function get(string $connectionKey): Connection {
    try {
      return Database::getConnection('default', $connectionKey);
    }
    catch (\Throwable $exception) {
      throw new DbtngException(sprintf('Database connection key "%s" is unavailable.', $connectionKey), 0, $exception);
    }
  }

}
