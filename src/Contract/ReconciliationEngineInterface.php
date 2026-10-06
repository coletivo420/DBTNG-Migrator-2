<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\ReconciliationReport;

/**
 * Compares the current primary and standby without mutating them.
 */
interface ReconciliationEngineInterface {

  public function reconcile(): ReconciliationReport;

}
