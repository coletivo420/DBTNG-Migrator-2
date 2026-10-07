<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Runtime state of the continuous synchronization worker.
 */
enum SyncWorkerState: string {
  case Starting = 'STARTING';
  case Idle = 'IDLE';
  case Draining = 'DRAINING';
  case Backoff = 'BACKOFF';
  case Blocked = 'BLOCKED';
  case Stopping = 'STOPPING';
  case Stopped = 'STOPPED';
  case Error = 'ERROR';
}
