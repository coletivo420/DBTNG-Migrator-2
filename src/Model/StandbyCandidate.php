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

  public function __construct(
    public DatabaseEngine $engine,
    public string $candidateIdentifier,
    public string $publishedIdentifier,
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

}
