<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\dbtng_migrator\Model\SyncWorkerConfiguration;

/**
 * Computes capped exponential retry delays.
 */
final class SyncBackoffPolicy {

  public function delay(int $consecutiveFailures, SyncWorkerConfiguration $configuration): int {
    if ($consecutiveFailures < 1) {
      return 0;
    }
    $delay = $configuration->backoffInitialSeconds;
    for ($attempt = 1; $attempt < $consecutiveFailures && $delay < $configuration->backoffMaxSeconds; $attempt++) {
      $delay = min($configuration->backoffMaxSeconds, $delay * 2);
    }
    return $delay;
  }

}
