<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

/**
 * Reads validated non-secret standby generation metadata.
 */
interface StandbyManifestReaderInterface {

  /**
   * Reads current published standby metadata.
   *
   * @return array<string, mixed>|null
   *   Current standby metadata, or NULL when no valid manifest exists.
   */
  public function readCurrent(string $physicalIdentity, ?string $generationId = NULL): ?array;

}
