<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Documented stages of a controlled manual authority transition.
 */
enum FailoverStage: string {
  case Normal = 'NORMAL';
  case Fenced = 'FENCED';
  case Drained = 'DRAINED';
  case Reconciled = 'RECONCILED';
  case ReadyToPromote = 'READY_TO_PROMOTE';
  case AuthoritySwitched = 'AUTHORITY_SWITCHED';
  case CaptureRebound = 'CAPTURE_REBOUND';
  case NewStandbyRequired = 'NEW_STANDBY_REQUIRED';
  case Healthy = 'HEALTHY';
}
