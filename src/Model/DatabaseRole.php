<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Runtime role of a configured database connection.
 */
enum DatabaseRole: string {
  case Primary = 'primary';
  case Standby = 'standby';
}
