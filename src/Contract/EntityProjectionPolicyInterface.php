<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

/**
 * Marker contract for future entity-aware revision/content projections.
 *
 * Implementations must operate with Drupal entity storage/table mappings rather
 * than blind table-name exclusions.
 */
interface EntityProjectionPolicyInterface {

  public function id(): string;

}
