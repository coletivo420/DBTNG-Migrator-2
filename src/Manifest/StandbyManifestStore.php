<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Manifest;

use Drupal\Core\Site\Settings;
use Drupal\dbtng_migrator\Exception\DbtngException;

/**
 * Persists non-secret, versioned metadata for a validated standby.
 */
final class StandbyManifestStore {

  /**
   * Writes metadata atomically under private storage.
   *
   * The physical destination identity is stored only as a SHA-256 identifier.
   *
   * @param array<string, mixed> $metadata
   *   Secret-free manifest fields.
   *
   * @return string
   *   Absolute path of the private manifest.
   */
  public function write(string $physicalIdentity, array $metadata): string {
    $privatePath = Settings::get('file_private_path');
    if (!is_string($privatePath) || $privatePath === '') {
      throw new DbtngException('Private storage is required to write standby metadata.');
    }
    $directory = rtrim($privatePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dbtng' . DIRECTORY_SEPARATOR . 'manifests';
    if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, TRUE) && !is_dir($directory))) {
      throw new DbtngException('Unable to create the private standby manifest directory.');
    }
    $realDirectory = realpath($directory);
    $privateRoot = realpath($privatePath);
    if ($realDirectory === FALSE || $privateRoot === FALSE
      || !str_starts_with($realDirectory . DIRECTORY_SEPARATOR, rtrim($privateRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
      || (defined('DRUPAL_ROOT') && str_starts_with($realDirectory . DIRECTORY_SEPARATOR, rtrim((string) realpath(DRUPAL_ROOT), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
      throw new DbtngException('Standby manifests must remain under private storage and outside the webroot.');
    }
    $directoryMode = fileperms($realDirectory);
    if ($directoryMode === FALSE || ($directoryMode & 0077) !== 0) {
      throw new DbtngException('Standby manifest directory must be owner-private (0700 or stricter).');
    }
    $this->assertSecretFree($metadata);
    $document = ['manifest_version' => 1, 'destination_id' => hash('sha256', $physicalIdentity)] + $metadata;
    try {
      $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
    catch (\JsonException $exception) {
      throw new DbtngException('Standby manifest metadata could not be encoded.', 0, $exception);
    }
    $path = $realDirectory . DIRECTORY_SEPARATOR . hash('sha256', $physicalIdentity) . '.json';
    $temporary = tempnam($realDirectory, '.dbtng-manifest-');
    if ($temporary === FALSE || !chmod($temporary, 0600)) {
      throw new DbtngException('Unable to create a private standby manifest artifact.');
    }
    try {
      if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded) || !rename($temporary, $path)) {
        throw new DbtngException('Unable to atomically publish standby metadata.');
      }
      if (!chmod($path, 0600)) {
        throw new DbtngException('Unable to protect the published standby manifest.');
      }
      return $path;
    }
    finally {
      if (is_file($temporary)) {
        unlink($temporary);
      }
    }
  }

  /**
   * Rejects field names that could contain credentials.
   *
   * @param array<string, mixed> $metadata
   */
  private function assertSecretFree(array $metadata): void {
    foreach ($metadata as $key => $value) {
      if (preg_match('/password|secret|credential|dsn|username/i', (string) $key) === 1) {
        throw new DbtngException('Secret-bearing fields are forbidden in standby manifests.');
      }
      if (is_array($value)) {
        $this->assertSecretFree($value);
      }
    }
  }

}
