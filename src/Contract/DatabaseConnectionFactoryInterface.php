<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\Core\Database\Connection;

/**
 * Obtains one named Drupal database connection without changing global roles. */
interface DatabaseConnectionFactoryInterface {

  public function get(string $connectionKey): Connection;

}
