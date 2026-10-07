<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\SyncStatus;
use Drupal\dbtng_migrator\Model\SyncBatchResult;

/**
 * Applies durable source changes to the standby.
 */
interface SyncEngineInterface {

  public function applyPending(?int $limit = NULL): SyncStatus;

  /**
   * Applies at most one bounded event batch and acknowledges exact IDs. */
  public function syncOnce(int $limit = 500): SyncBatchResult;

  public function status(): SyncStatus;

}
