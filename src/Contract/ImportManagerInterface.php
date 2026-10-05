<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\ImportRequest;
use Drupal\dbtng_migrator\Model\SnapshotManifest;

/**
 * Imports the configured primary into a proven-empty standby destination.
 */
interface ImportManagerInterface {

  public function import(ImportRequest $request): SnapshotManifest;

}
