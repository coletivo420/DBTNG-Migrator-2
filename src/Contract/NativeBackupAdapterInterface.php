<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\dbtng_migrator\Model\BackupCompression;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\NativeBackupArtifact;
use Drupal\dbtng_migrator\Model\ResolvedDatabase;

/**
 * Engine-specific native artifact writer. */
interface NativeBackupAdapterInterface {

  public function supports(DatabaseEngine $engine): bool;

  public function create(ResolvedDatabase $database, BackupCompression $compression, string $directory): NativeBackupArtifact;

}
