<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\SnapshotManifest;
use Drupal\dbtng_migrator\Model\SnapshotRequest;

/**
 * Orchestrates creation of a validated standby snapshot.
 */
interface SnapshotManagerInterface {

  public function create(SnapshotRequest $request): SnapshotManifest;

}
