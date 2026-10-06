<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Model\DestinationStateInspection;

/**
 * Determines whether a destination can accept a bootstrap import.
 */
interface DestinationStateInspectorInterface {

  public function supports(Connection $connection): bool;

  public function inspect(Connection $connection): DestinationStateInspection;

}
