<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\RebuildResult;
use Drupal\dbtng_migrator\Model\SnapshotRequest;

/**
 * Coordinates isolated candidate rebuilds and publication.
 */
interface RebuildManagerInterface {

  public function rebuild(SnapshotRequest $request): RebuildResult;

}
