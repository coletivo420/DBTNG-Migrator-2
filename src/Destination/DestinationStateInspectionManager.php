<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Destination;

use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Destination\Mysql\MysqlDestinationStateInspector;
use Drupal\dbtng_migrator\Destination\Sqlite\SqliteDestinationStateInspector;
use Drupal\dbtng_migrator\Model\DestinationStateInspection;

/**
 * Selects a destination inspector by its actual engine. */
final class DestinationStateInspectionManager {

  public function __construct(
    private readonly MysqlDestinationStateInspector $mysql,
    private readonly SqliteDestinationStateInspector $sqlite,
  ) {}

  public function inspect(Connection $connection): DestinationStateInspection {
    foreach ([$this->mysql, $this->sqlite] as $inspector) {
      if ($inspector->supports($connection)) {
        return $inspector->inspect($connection);
      }
    }
    throw new \InvalidArgumentException(sprintf('No destination inspector supports driver "%s".', $connection->driver()));
  }

}
