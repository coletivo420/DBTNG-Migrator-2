<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Minimal versioned metadata for a standby artifact.
 */
final readonly class SnapshotManifest {

  public function __construct(
    public string $format,
    public string $snapshotId,
    public string $createdAt,
    public string $profile,
    public bool $portable,
    public bool $activatable,
    public int $tableCount,
    public int $rowCount,
    public ?string $sha256 = NULL,
  ) {}

}
