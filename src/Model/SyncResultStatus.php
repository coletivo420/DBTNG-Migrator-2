<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Outcome of a single bounded sync operation. */
enum SyncResultStatus: string {
  case NoWork = 'NO_WORK';
  case CaughtUp = 'CAUGHT_UP';
  case MorePending = 'MORE_PENDING';
  case Blocked = 'BLOCKED';
  case Failed = 'FAILED';
}
