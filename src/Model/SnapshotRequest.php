<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Input for a future snapshot orchestration run.
 */
final readonly class SnapshotRequest {

  public function __construct(
    public string $sourceKey = 'default',
    public string $profile = 'clean',
    public bool $strict = TRUE,
  ) {}

}
