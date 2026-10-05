<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Input for a future snapshot orchestration run.
 */
final readonly class SnapshotRequest {

  public DatabaseTopology $topology;

  public function __construct(
    public ReplicationProfile $profile = ReplicationProfile::Full,
    public bool $strict = TRUE,
    ?DatabaseTopology $topology = NULL,
  ) {
    $this->topology = $topology ?? DatabaseTopology::default();

    if (!$this->topology->supportsProfile($profile)) {
      throw new \InvalidArgumentException(sprintf(
        'Replication profile "%s" is not supported for %s primary -> %s standby.',
        $profile->value,
        $this->topology->primaryEngine->label(),
        $this->topology->standbyEngine->label(),
      ));
    }
  }

}
