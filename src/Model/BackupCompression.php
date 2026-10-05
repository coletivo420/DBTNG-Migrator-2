<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Compression applied to a downloadable native database backup.
 */
enum BackupCompression: string {
  case None = 'none';
  case Gzip = 'gzip';
}
