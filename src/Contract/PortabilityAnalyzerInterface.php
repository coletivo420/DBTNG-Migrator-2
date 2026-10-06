<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\PortabilityReport;

/**
 * Analyzes whether physical source semantics have a supported target mapping. */
interface PortabilityAnalyzerInterface {

  public function analyze(DatabaseInventory $inventory): PortabilityReport;

}
