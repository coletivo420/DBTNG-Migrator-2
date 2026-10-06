<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Schema;

use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Source\Mysql\MysqlFamilySchemaIntrospector;
use Drupal\dbtng_migrator\Source\Sqlite\SqliteSchemaIntrospector;
use Drupal\dbtng_migrator\Model\DatabaseInventory;

/**
 * Selects a physical introspector by the actual Drupal connection driver. */
final class SchemaIntrospectionManager {

  public function __construct(
    private readonly MysqlFamilySchemaIntrospector $mysql,
    private readonly SqliteSchemaIntrospector $sqlite,
  ) {}

  public function inspect(Connection $connection): DatabaseInventory {
    foreach ([$this->mysql, $this->sqlite] as $introspector) {
      if ($introspector->supports($connection)) {
        return $introspector->inspect($connection);
      }
    }
    throw new \InvalidArgumentException(sprintf('No physical schema introspector supports driver "%s".', $connection->driver()));
  }

}
