<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Metadata for a generated native database backup.
 */
final readonly class NativeBackupArtifact {

  public function __construct(
    public DatabaseEngine $engine,
    public DatabaseRole $role,
    public NativeBackupFormat $format,
    public string $path,
    public string $downloadName,
    public string $mimeType,
    public int $bytes,
    public string $sha256,
  ) {
    if ($path === '' || $downloadName === '') {
      throw new \InvalidArgumentException('Backup artifact path and download name cannot be empty.');
    }

    if ($bytes < 0) {
      throw new \InvalidArgumentException('Backup artifact size cannot be negative.');
    }

    if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
      throw new \InvalidArgumentException('Backup artifact SHA-256 must be a lowercase 64-character hexadecimal digest.');
    }

    if ($format->databaseEngine() !== $engine) {
      throw new \InvalidArgumentException('Backup artifact format does not match its database engine.');
    }
  }

}
