<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Read-only failover readiness result.
 */
enum FailoverReadinessStatus: string {
  case Ready = 'READY';
  case NotReady = 'NOT_READY';
}
