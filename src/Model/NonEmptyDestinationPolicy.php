<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Action to take when a standby destination contains existing state.
 */
enum NonEmptyDestinationPolicy: string {
  case Abort = 'abort';
  case BackupThenClear = 'backup_then_clear';
  case Clear = 'clear';

  public function destructive(): bool {
    return $this !== self::Abort;
  }

  public function createsSafetyBackup(): bool {
    return $this === self::BackupThenClear;
  }

}
