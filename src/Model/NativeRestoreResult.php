<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Result of a validated same-engine standby restore. */
final readonly class NativeRestoreResult {

  public function __construct(
    public DatabaseEngine $engine,
    public NativeBackupFormat $format,
    public DestinationPreparationResult $preparation,
    public string $manifestPath,
  ) {}

}
