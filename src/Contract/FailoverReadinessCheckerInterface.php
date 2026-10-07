<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\FailoverReadinessReport;

/**
 * Evaluates read-only data-plane prerequisites for controlled failover.
 */
interface FailoverReadinessCheckerInterface {

  public function check(): FailoverReadinessReport;

}
