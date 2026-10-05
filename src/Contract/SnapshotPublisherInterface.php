<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\SnapshotManifest;
use Drupal\dbtng_migrator\Model\StandbyCandidate;

/**
 * Promotes an already validated isolated standby candidate.
 */
interface SnapshotPublisherInterface {

  public function publish(
    StandbyCandidate $candidate,
    SnapshotManifest $manifest,
  ): void;

}
