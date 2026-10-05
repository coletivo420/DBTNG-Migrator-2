<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Input for a future snapshot orchestration run.
 */
final readonly class SnapshotRequest {

  public DatabaseTopology $topology;

  public string $profile;

  public bool $strict;

  public function __construct(
    string $profile = 'clean',
    bool $strict = TRUE,
    ?DatabaseTopology $topology = NULL,
  ) {
    $this->topology = $topology ?? DatabaseTopology::default();
    $this->profile = $profile;
    $this->strict = $strict;

    if ($profile !== 'full' && !$this->topology->supportsCleanStandby()) {
      throw new \InvalidArgumentException(
        'Clean replication profiles are initially supported only when SQLite is the standby database.',
      );
    }
  }

}
