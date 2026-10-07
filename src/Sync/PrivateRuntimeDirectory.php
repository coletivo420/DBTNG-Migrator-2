<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\Core\Site\Settings;
use Drupal\dbtng_migrator\Exception\DbtngException;

/**
 * Resolves owner-private DBTNG runtime storage outside the webroot.
 */
final class PrivateRuntimeDirectory {

  public function __construct(private readonly ?string $privatePath = NULL) {}

  public function path(): string {
    $configured = $this->privatePath ?? Settings::get('file_private_path');
    if (!is_string($configured) || $configured === '' || is_link($configured)) {
      throw new DbtngException('A real private path is required for DBTNG worker runtime state.');
    }
    $root = realpath($configured);
    if ($root === FALSE || !is_dir($root)) {
      throw new DbtngException('The configured private path is unavailable for DBTNG worker runtime state.');
    }
    $directory = $root . DIRECTORY_SEPARATOR . 'dbtng' . DIRECTORY_SEPARATOR . 'runtime';
    if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, TRUE) && !is_dir($directory))) {
      throw new DbtngException('Unable to create the private DBTNG runtime directory.');
    }
    $real = realpath($directory);
    if ($real === FALSE || !str_starts_with($real . DIRECTORY_SEPARATOR, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
      throw new DbtngException('DBTNG runtime state escaped configured private storage.');
    }
    if (defined('DRUPAL_ROOT')) {
      $webroot = realpath((string) DRUPAL_ROOT);
      if (is_string($webroot) && str_starts_with($real . DIRECTORY_SEPARATOR, rtrim($webroot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        throw new DbtngException('DBTNG runtime state must remain outside the webroot.');
      }
    }
    $mode = fileperms($real);
    if ($mode === FALSE || ($mode & 0077) !== 0) {
      throw new DbtngException('DBTNG runtime directory must be owner-private (0700 or stricter).');
    }
    return $real;
  }

}
