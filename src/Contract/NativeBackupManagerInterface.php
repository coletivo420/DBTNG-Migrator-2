<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\NativeBackupArtifact;
use Drupal\dbtng_migrator\Model\NativeBackupRequest;

/**
 * Creates same-engine native database backup artifacts.
 */
interface NativeBackupManagerInterface {

  public function create(
    DatabaseTopology $topology,
    NativeBackupRequest $request,
  ): NativeBackupArtifact;

}
