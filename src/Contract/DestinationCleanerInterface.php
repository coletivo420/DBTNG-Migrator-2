<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\DatabaseTopology;

/**
 * Clears the configured standby before an explicitly authorized import/restore.
 */
interface DestinationCleanerInterface {

  public function clearStandby(DatabaseTopology $topology): void;

}
