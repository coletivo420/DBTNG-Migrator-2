<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Request to restore a native backup into the configured standby.
 *
 * Native restore is same-engine. Cross-engine migration uses ImportRequest.
 */
final readonly class NativeRestoreRequest {

  public function __construct(
    public string $backupPath,
    public NativeBackupFormat $format,
    public NonEmptyDestinationPolicy $nonEmptyPolicy = NonEmptyDestinationPolicy::Abort,
  ) {
    if ($backupPath === '') {
      throw new \InvalidArgumentException('Native restore backup path cannot be empty.');
    }
  }

}
