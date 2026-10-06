<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Backup;

use Drupal\Core\Site\Settings;
use Drupal\dbtng_migrator\Exception\DbtngException;

/**
 * Creates and validates private artifact directories outside the webroot. */
final class BackupDirectoryManager {

  public function prepare(?string $requestedDirectory = NULL): string {
    $privatePath = Settings::get('file_private_path');
    $defaultDirectory = is_string($privatePath) && $privatePath !== ''
      ? rtrim($privatePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dbtng' . DIRECTORY_SEPARATOR . 'backups'
      : NULL;
    $base = $requestedDirectory ?? $defaultDirectory;
    if (!is_string($base) || $base === '' || !str_starts_with($base, DIRECTORY_SEPARATOR)) {
      throw new DbtngException('A private absolute backup directory must be configured or provided.');
    }
    $directory = rtrim($base, DIRECTORY_SEPARATOR);
    if (defined('DRUPAL_ROOT') && $this->isInside($directory, (string) DRUPAL_ROOT)) {
      throw new DbtngException('Backup artifacts must be stored outside the Drupal webroot.');
    }
    if (is_link($directory)) {
      throw new DbtngException('Backup directory may not be a symbolic link.');
    }
    $created = FALSE;
    if (!is_dir($directory)) {
      if (!mkdir($directory, 0700, TRUE) && !is_dir($directory)) {
        throw new DbtngException('Unable to create the private backup directory.');
      }
      $created = TRUE;
    }
    $realDirectory = realpath($directory);
    if ($realDirectory === FALSE || !is_writable($realDirectory)) {
      throw new DbtngException('The private backup directory is unavailable or not writable.');
    }
    if (defined('DRUPAL_ROOT') && $this->isInside($realDirectory, (string) DRUPAL_ROOT)) {
      throw new DbtngException('Backup artifacts must be stored outside the Drupal webroot.');
    }
    if ($created && !chmod($realDirectory, 0700)) {
      throw new DbtngException('Unable to restrict private backup directory permissions.');
    }
    $permissions = fileperms($realDirectory);
    if ($permissions === FALSE || ($permissions & 0077) !== 0) {
      throw new DbtngException('The output directory must be private to its owner (mode 0700 or stricter).');
    }
    return $realDirectory;
  }

  private function isInside(string $path, string $parent): bool {
    $parent = rtrim((string) realpath($parent), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return $parent !== DIRECTORY_SEPARATOR && str_starts_with($path, $parent);
  }

}
