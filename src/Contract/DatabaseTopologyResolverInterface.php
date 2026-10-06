<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\ResolvedTopology;

/**
 * Resolves and validates the pre-bootstrap primary/standby connection roles. */
interface DatabaseTopologyResolverInterface {

  public function resolve(): ResolvedTopology;

}
