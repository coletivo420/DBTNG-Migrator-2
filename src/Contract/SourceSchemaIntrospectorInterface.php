<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Model\DatabaseInventory;

/**
 * Builds DBTNG's portable inventory from a physical source connection.
 */
interface SourceSchemaIntrospectorInterface {

  public function supports(Connection $connection): bool;

  public function inspect(Connection $connection): DatabaseInventory;

}
