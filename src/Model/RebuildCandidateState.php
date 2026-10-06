<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Lifecycle state for an isolated standby rebuild candidate. */
enum RebuildCandidateState: string {
  case Created = 'created';
  case Building = 'building';
  case Built = 'built';
  case Validating = 'validating';
  case Valid = 'valid';
  case Invalid = 'invalid';
  case Publishing = 'publishing';
  case Published = 'published';
  case Discarded = 'discarded';
}
