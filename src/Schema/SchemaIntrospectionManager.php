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

  /**
   * Introspects the physical schema for a configured Drupal connection.
   *
   * @param list<string>|null $onlyTables
   *   Optional exact table names to include.
   */
  public function inspect(Connection $connection, ?array $onlyTables = NULL): DatabaseInventory {
    foreach ([$this->mysql, $this->sqlite] as $introspector) {
      if ($introspector->supports($connection)) {
        return $introspector instanceof MysqlFamilySchemaIntrospector
          ? $introspector->inspect($connection, $onlyTables)
          : $introspector->inspect($connection);
      }
    }
    throw new \InvalidArgumentException(sprintf('No physical schema introspector supports driver "%s".', $connection->driver()));
  }

}
