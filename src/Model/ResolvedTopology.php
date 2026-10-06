<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Fully resolved runtime roles and their already-open connections. */
final readonly class ResolvedTopology {

  public function __construct(
    public DatabaseTopology $topology,
    public ResolvedDatabase $primary,
    public ResolvedDatabase $standby,
    public ReplicationProfile $profile,
  ) {
    if ($primary->identity === $standby->identity) {
      throw new \InvalidArgumentException('Primary and standby resolve to the same physical database.');
    }
    if ($primary->engine === $standby->engine) {
      throw new \InvalidArgumentException('Primary and standby engines must be different.');
    }
    if (!$topology->supportsProfile($profile)) {
      throw new \InvalidArgumentException('The configured replication profile is not valid for the resolved standby engine.');
    }
  }

}
