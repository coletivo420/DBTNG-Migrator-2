<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Failover;

use Drupal\dbtng_migrator\Model\FailoverStage;

/**
 * Pure transition policy for the documented manual failover workflow.
 */
final class FailoverTransitionPolicy {

  public function canTransition(FailoverStage $from, FailoverStage $to): bool {
    return match ($from) {
      FailoverStage::Normal => $to === FailoverStage::Fenced,
      FailoverStage::Fenced => $to === FailoverStage::Drained,
      FailoverStage::Drained => $to === FailoverStage::Reconciled,
      FailoverStage::Reconciled => $to === FailoverStage::ReadyToPromote,
      FailoverStage::ReadyToPromote => $to === FailoverStage::AuthoritySwitched,
      FailoverStage::AuthoritySwitched => $to === FailoverStage::CaptureRebound,
      FailoverStage::CaptureRebound => $to === FailoverStage::NewStandbyRequired,
      FailoverStage::NewStandbyRequired => $to === FailoverStage::Healthy,
      FailoverStage::Healthy => FALSE,
    };
  }

  public function assertTransition(FailoverStage $from, FailoverStage $to): void {
    if (!$this->canTransition($from, $to)) {
      throw new \LogicException(sprintf(
        'Unsafe failover transition %s -> %s.',
        $from->value,
        $to->value,
      ));
    }
  }

}
