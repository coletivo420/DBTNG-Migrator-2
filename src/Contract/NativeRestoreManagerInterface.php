<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\NativeRestoreRequest;
use Drupal\dbtng_migrator\Model\NativeRestoreResult;

/**
 * Restores a same-engine native backup into the configured standby.
 */
interface NativeRestoreManagerInterface {

  public function restore(
    DatabaseTopology $topology,
    NativeRestoreRequest $request,
  ): NativeRestoreResult;

}
