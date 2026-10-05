<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Input for a cross-engine logical import into the configured standby.
 */
final readonly class ImportRequest {

  public DatabaseTopology $topology;

  public function __construct(
    public ReplicationProfile $profile = ReplicationProfile::Full,
    public bool $strict = TRUE,
    public NonEmptyDestinationPolicy $nonEmptyPolicy = NonEmptyDestinationPolicy::Abort,
    ?DatabaseTopology $topology = NULL,
  ) {
    $this->topology = $topology ?? DatabaseTopology::default();

    if (!$this->topology->supportsProfile($profile)) {
      throw new \InvalidArgumentException(sprintf(
        'Replication profile "%s" is not supported for %s primary -> %s standby import.',
        $profile->value,
        $this->topology->primaryEngine->label(),
        $this->topology->standbyEngine->label(),
      ));
    }
  }

}
