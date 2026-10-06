<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Model;

use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\RebuildCandidateState;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\StandbyCandidate;
use PHPUnit\Framework\TestCase;

/**
 * Tests candidate validation as a hard publication gate.
 */
final class StandbyCandidateTest extends TestCase {

  public function testOnlyValidatedPublishingStateCanBePublished(): void {
    $candidate = new StandbyCandidate(
      DatabaseEngine::Sqlite,
      '/private/candidate.sqlite',
      '/private/standby.sqlite',
      str_repeat('a', 32),
      DatabaseEngine::MysqlFamily,
      ReplicationProfile::Full,
      '2026-10-06T00:00:00+00:00',
    );
    self::assertFalse($candidate->canPublish());
    self::assertTrue($candidate->withBuildMetrics(2, 5, 0)->withState(RebuildCandidateState::Publishing, TRUE)->canPublish());
    self::assertFalse($candidate->withBuildMetrics(2, 5, 0)->canPublish());
  }

}
