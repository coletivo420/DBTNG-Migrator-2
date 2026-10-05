<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\SyncStatus;

/**
 * Applies durable source changes to the standby.
 */
interface SyncEngineInterface {

  public function applyPending(?int $limit = NULL): SyncStatus;

  public function status(): SyncStatus;

}
