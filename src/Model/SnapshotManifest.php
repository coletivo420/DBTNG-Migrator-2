<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Minimal versioned metadata for a standby artifact/state.
 */
final readonly class SnapshotManifest {

  /**
   * Creates a versioned description of a standby result.
   *
   * @param list<string> $intentionalExclusions
   *   Explicit tables or objects omitted by the selected projection.
   */

  public function __construct(
    public string $format,
    public string $snapshotId,
    public string $createdAt,
    public DatabaseEngine $primaryEngine,
    public DatabaseEngine $standbyEngine,
    public ReplicationProfile $profile,
    public bool $portable,
    public bool $activatable,
    public int $tableCount,
    public int $rowCount,
    public ?string $sha256 = NULL,
    public int $bytesTransferred = 0,
    public int $peakMemoryBytes = 0,
    public int $portabilityWarnings = 0,
    public ?string $safetyBackupPath = NULL,
    public ?string $safetyBackupSha256 = NULL,
    public string $previousDestinationState = 'empty',
    public string $destinationPolicy = 'abort',
    public ?string $manifestPath = NULL,
    public ?string $rebuildId = NULL,
    public ?string $schemaFingerprint = NULL,
    public ?string $reconciliationStatus = NULL,
    public ?string $candidateIdentifier = NULL,
    public ?string $publishedIdentifier = NULL,
    public ?string $previousPublishedIdentifier = NULL,
    public ?string $validationStatus = NULL,
    public bool $fullFidelity = TRUE,
    public array $intentionalExclusions = [],
  ) {}

}
