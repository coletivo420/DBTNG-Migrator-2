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
    $currentPath = $realDirectory . DIRECTORY_SEPARATOR . hash('sha256', $physicalIdentity) . '.current.json';
    if (is_link($currentPath) || (is_file($currentPath) && !unlink($currentPath))) {
      throw new DbtngException('Unable to retire the previous generation marker before writing current standby metadata.');
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
   * Writes immutable metadata for a candidate without replacing live state.
   *
   * @param array<string, mixed> $metadata
   *   Secret-free candidate metadata.
   *
   * @return string
   *   Absolute path of the candidate manifest.
   */
  public function writeVersion(string $physicalIdentity, string $generationId, array $metadata): string {
    $this->assertGenerationId($generationId);
    $directory = $this->directory();
    $this->assertSecretFree($metadata);
    $document = [
      'manifest_version' => 2,
      'destination_id' => hash('sha256', $physicalIdentity),
      'generation_id' => $generationId,
    ] + $metadata;
    return $this->writeDocument($directory . DIRECTORY_SEPARATOR . hash('sha256', $physicalIdentity) . '.' . $generationId . '.json', $document);
  }

  /**
   * Marks a previously written generation as current using an atomic pointer.
   *
   * The marker is a recovery hint only; the engine-specific physical publish
   * remains authoritative.
   */
  public function publishVersion(string $physicalIdentity, string $generationId): void {
    $this->assertGenerationId($generationId);
    if ($this->readVersion($physicalIdentity, $generationId) === NULL) {
      throw new DbtngException('Cannot publish a generation without a valid manifest.');
    }
    $this->writeDocument($this->directory() . DIRECTORY_SEPARATOR . hash('sha256', $physicalIdentity) . '.current.json', [
      'manifest_version' => 2,
      'destination_id' => hash('sha256', $physicalIdentity),
      'generation_id' => $generationId,
    ]);
  }

  public function markVersionPublished(string $physicalIdentity, string $generationId): void {
    $metadata = $this->readVersion($physicalIdentity, $generationId);
    if ($metadata === NULL) {
      throw new DbtngException('Cannot mark a missing snapshot generation as published.');
    }
    unset($metadata['manifest_version'], $metadata['destination_id'], $metadata['generation_id']);
    $metadata['lifecycle_state'] = 'published';
    $metadata['published_at'] = gmdate(DATE_ATOM);
    $this->writeVersion($physicalIdentity, $generationId, $metadata);
  }

  /**
   * Reads one validated immutable version by generation identifier.
   *
   * @return array<string, mixed>|null
   */
  public function readVersion(string $physicalIdentity, string $generationId): ?array {
    $this->assertGenerationId($generationId);
    return $this->readDocument($this->directory() . DIRECTORY_SEPARATOR . hash('sha256', $physicalIdentity) . '.' . $generationId . '.json', $physicalIdentity);
  }

  /**
   * Reads current published metadata or the legacy flat manifest.
   *
   * @return array<string, mixed>|null
   */
  public function readCurrent(string $physicalIdentity, ?string $generationId = NULL): ?array {
    if ($generationId !== NULL) {
      return $this->readVersion($physicalIdentity, $generationId);
    }
    $currentPath = $this->directory() . DIRECTORY_SEPARATOR . hash('sha256', $physicalIdentity) . '.current.json';
    if (is_file($currentPath)) {
      $marker = $this->readDocument($currentPath, $physicalIdentity);
      $id = $marker['generation_id'] ?? NULL;
      if (!is_string($id)) {
        return NULL;
      }
      return $this->readVersion($physicalIdentity, $id);
    }
    return $this->readDocument($this->directory() . DIRECTORY_SEPARATOR . hash('sha256', $physicalIdentity) . '.json', $physicalIdentity);
  }

  /**
   * Lists immutable manifest versions for a physical destination.
   *
   * @return list<array<string, mixed>>
   */
  public function listVersions(string $physicalIdentity): array {
    $pattern = $this->directory() . DIRECTORY_SEPARATOR . hash('sha256', $physicalIdentity) . '.*.json';
    $versions = [];
    foreach (glob($pattern) ?: [] as $path) {
      if (str_ends_with($path, '.current.json')) {
        continue;
      }
      $document = json_decode((string) file_get_contents($path), TRUE);
      if (is_array($document) && ($document['destination_id'] ?? NULL) === hash('sha256', $physicalIdentity)) {
        $versions[] = $document;
      }
    }
    return $versions;
  }

  public function deleteVersion(string $physicalIdentity, string $generationId): void {
    $this->assertGenerationId($generationId);
    $path = $this->directory() . DIRECTORY_SEPARATOR . hash('sha256', $physicalIdentity) . '.' . $generationId . '.json';
    if (is_link($path)) {
      throw new DbtngException('Refusing to remove a linked standby manifest.');
    }
    if (is_file($path) && !unlink($path)) {
      throw new DbtngException('Unable to remove a discarded candidate manifest.');
    }
  }

  private function directory(): string {
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
    return $realDirectory;
  }

  /**
   * Atomically writes a private JSON document.
   *
   * @param array<string, mixed> $document
   */
  private function writeDocument(string $path, array $document): string {
    try {
      $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
    catch (\JsonException $exception) {
      throw new DbtngException('Standby manifest metadata could not be encoded.', 0, $exception);
    }
    $temporary = tempnam(dirname($path), '.dbtng-manifest-');
    if ($temporary === FALSE || !chmod($temporary, 0600)) {
      throw new DbtngException('Unable to create a private standby manifest artifact.');
    }
    try {
      if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded) || !rename($temporary, $path) || !chmod($path, 0600)) {
        throw new DbtngException('Unable to atomically publish standby metadata.');
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
   * Reads a private manifest only when its physical destination matches.
   *
   * @return array<string, mixed>|null
   */
  private function readDocument(string $path, string $physicalIdentity): ?array {
    if (!is_file($path) || is_link($path)) {
      return NULL;
    }
    $json = file_get_contents($path);
    if (!is_string($json)) {
      return NULL;
    }
    try {
      $document = json_decode($json, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    if (!is_array($document) || ($document['destination_id'] ?? NULL) !== hash('sha256', $physicalIdentity)) {
      return NULL;
    }
    return $document;
  }

  private function assertGenerationId(string $generationId): void {
    if (preg_match('/^[a-f0-9]{32}$/D', $generationId) !== 1) {
      throw new DbtngException('Invalid snapshot generation identifier.');
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
