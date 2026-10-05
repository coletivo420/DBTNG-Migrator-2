<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Supported standby replication profiles.
 */
enum ReplicationProfile: string {
  case Full = 'full';
  case Clean = 'clean';

  public function isLossyProjection(): bool {
    return $this === self::Clean;
  }

}
