<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Outcome and measured transfer data for a candidate rebuild. */
final readonly class RebuildResult {

  public function __construct(
    public SnapshotManifest $manifest,
    public string $candidateId,
    public string $candidateIdentifier,
    public string $publishedIdentifier,
    public ?string $previousPublishedIdentifier,
    public RebuildCandidateState $state,
    public string $reconciliationStatus,
    public int $durationMilliseconds,
    public int $batchCount,
  ) {}

}
