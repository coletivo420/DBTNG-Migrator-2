<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Read-only comparison outcome for the configured primary and standby. */
enum ReconciliationStatus: string {
  case Match = 'MATCH';
  case Drift = 'DRIFT';
  case Blocked = 'BLOCKED';
  case RebuildRequired = 'REBUILD_REQUIRED';
}
