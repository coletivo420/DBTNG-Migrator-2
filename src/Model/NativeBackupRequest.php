<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Request for a native backup artifact suitable for download or safekeeping.
 */
final readonly class NativeBackupRequest {

  public function __construct(
    public DatabaseRole $role = DatabaseRole::Primary,
    public BackupCompression $compression = BackupCompression::Gzip,
  ) {}

}
