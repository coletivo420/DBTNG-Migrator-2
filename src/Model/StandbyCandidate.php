<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Engine-neutral description of an isolated standby rebuild candidate.
 *
 * Candidate and published identifiers are interpreted by the destination
 * adapter. They may be filesystem paths for SQLite or logical database/
 * namespace identifiers for a server database.
 */
final readonly class StandbyCandidate {

  /**
   * Creates immutable metadata for an isolated standby candidate.
   *
   * @param array<string, mixed> $manifestMetadata
   *   Secret-free metadata prepared before publication.
   */

  public function __construct(
    public DatabaseEngine $engine,
    public string $candidateIdentifier,
    public string $publishedIdentifier,
    public string $uuid = '',
    public ?DatabaseEngine $primaryEngine = NULL,
    public ?ReplicationProfile $profile = NULL,
    public string $createdAt = '',
    public RebuildCandidateState $state = RebuildCandidateState::Created,
    public bool $validated = FALSE,
    public int $tableCount = 0,
    public int $rowCount = 0,
    public int $portabilityWarnings = 0,
    public array $manifestMetadata = [],
  ) {
    if ($candidateIdentifier === '' || $publishedIdentifier === '') {
      throw new \InvalidArgumentException('Standby candidate identifiers cannot be empty.');
    }

    if ($candidateIdentifier === $publishedIdentifier) {
      throw new \InvalidArgumentException(
        'A rebuild candidate must be isolated from the currently published standby.',
      );
    }
  }

  public function withState(RebuildCandidateState $state, bool $validated = FALSE): self {
    return new self(
      $this->engine,
      $this->candidateIdentifier,
      $this->publishedIdentifier,
      $this->uuid,
      $this->primaryEngine,
      $this->profile,
      $this->createdAt,
      $state,
      $validated,
      $this->tableCount,
      $this->rowCount,
      $this->portabilityWarnings,
      $this->manifestMetadata,
    );
  }

  public function withBuildMetrics(int $tableCount, int $rowCount, int $warnings): self {
    return new self(
      $this->engine,
      $this->candidateIdentifier,
      $this->publishedIdentifier,
      $this->uuid,
      $this->primaryEngine,
      $this->profile,
      $this->createdAt,
      RebuildCandidateState::Built,
      TRUE,
      $tableCount,
      $rowCount,
      $warnings,
      $this->manifestMetadata,
    );
  }

  public function canPublish(): bool {
    return $this->validated && $this->state === RebuildCandidateState::Publishing;
  }

}
