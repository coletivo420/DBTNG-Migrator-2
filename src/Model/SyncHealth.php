<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Operator-facing continuous synchronization health.
 */
enum SyncHealth: string {
  case Healthy = 'HEALTHY';
  case CatchingUp = 'CATCHING_UP';
  case Lagging = 'LAGGING';
  case Backoff = 'BACKOFF';
  case Blocked = 'BLOCKED';
  case Stale = 'STALE';
  case Error = 'ERROR';
}
