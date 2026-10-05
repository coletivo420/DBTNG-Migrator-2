<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\SnapshotManifest;

/**
 * Publishes an already validated temporary snapshot artifact.
 */
interface SnapshotPublisherInterface {

  public function publish(
    string $temporaryDatabasePath,
    string $publishedDatabasePath,
    SnapshotManifest $manifest,
  ): void;

}
