<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Failover;

use Drupal\dbtng_migrator\Failover\FailoverTransitionPolicy;
use Drupal\dbtng_migrator\Model\FailoverStage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the documented manual failover stage order.
 */
final class FailoverTransitionPolicyTest extends TestCase {

  /**
   * Provides every allowed adjacent transition in order.
   *
   * @return list<array{FailoverStage, FailoverStage}>
   *   Allowed adjacent transitions.
   */
  public static function allowedTransitions(): array {
    return [
      [FailoverStage::Normal, FailoverStage::Fenced],
      [FailoverStage::Fenced, FailoverStage::Drained],
      [FailoverStage::Drained, FailoverStage::Reconciled],
      [FailoverStage::Reconciled, FailoverStage::ReadyToPromote],
      [FailoverStage::ReadyToPromote, FailoverStage::AuthoritySwitched],
      [FailoverStage::AuthoritySwitched, FailoverStage::CaptureRebound],
      [FailoverStage::CaptureRebound, FailoverStage::NewStandbyRequired],
      [FailoverStage::NewStandbyRequired, FailoverStage::Healthy],
    ];
  }

  #[DataProvider('allowedTransitions')]
  public function testOnlyDocumentedAdjacentTransitionIsAllowed(
    FailoverStage $from,
    FailoverStage $to,
  ): void {
    $policy = new FailoverTransitionPolicy();
    self::assertTrue($policy->canTransition($from, $to));
    $policy->assertTransition($from, $to);
  }

  public function testSkippingFenceIsRejected(): void {
    $policy = new FailoverTransitionPolicy();

    self::assertFalse($policy->canTransition(
      FailoverStage::Normal,
      FailoverStage::ReadyToPromote,
    ));

    $this->expectException(\LogicException::class);
    $policy->assertTransition(
      FailoverStage::Normal,
      FailoverStage::ReadyToPromote,
    );
  }

  public function testHealthyIsTerminalForThisWorkflow(): void {
    $policy = new FailoverTransitionPolicy();
    foreach (FailoverStage::cases() as $target) {
      self::assertFalse($policy->canTransition(FailoverStage::Healthy, $target));
    }
  }

}
