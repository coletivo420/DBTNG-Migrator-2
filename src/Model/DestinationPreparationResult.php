<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Result of inspecting and, when requested, preparing the standby. */
final readonly class DestinationPreparationResult {

  public function __construct(
    public DestinationState $previousState,
    public NonEmptyDestinationPolicy $policy,
    public ?NativeBackupArtifact $safetyBackup = NULL,
  ) {}

}
