<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Snapshot;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Contract\FailureInjectorInterface;
use Drupal\dbtng_migrator\Contract\SnapshotPublisherInterface;
use Drupal\dbtng_migrator\Manifest\StandbyManifestStore;
use Drupal\dbtng_migrator\Model\SnapshotManifest;

/**
 * Publishes immutable SQLite generations by atomically replacing a symlink. */
final class SqliteSnapshotPublisher implements SnapshotPublisherInterface {

  public function __construct(
    private readonly StandbyManifestStore $manifests,
    private readonly FailureInjectorInterface $failures,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public function publish(CandidateContext $context, SnapshotManifest $manifest): void {
    if (!$context->candidate->canPublish()) {
      throw new DbtngException('An unvalidated SQLite candidate cannot be published.');
    }
    if ($context->candidatePath === NULL || $context->partialDirectory === NULL || $context->finalDirectory === NULL) {
      throw new DbtngException('SQLite publication received an incomplete candidate context.');
    }
    $candidatePath = $context->candidatePath;
    $partialDirectory = $context->partialDirectory;
    $finalDirectory = $context->finalDirectory;
    $this->assertValidFile($candidatePath);
    $context->connection()->query('PRAGMA wal_checkpoint(TRUNCATE)');
    $context->releaseConnection();
    $this->removeSidecars($candidatePath);
    $databaseFile = $partialDirectory . DIRECTORY_SEPARATOR . 'dbtng.sqlite';
    if (!rename($candidatePath, $databaseFile) || !chmod($databaseFile, 0600)) {
      throw new DbtngException('Unable to finalize the SQLite candidate artifact.');
    }
    $stream = fopen($databaseFile, 'rb');
    if ($stream === FALSE) {
      throw new DbtngException('Unable to open the finalized SQLite candidate.');
    }
    try {
      if (function_exists('fsync') && !fsync($stream)) {
        throw new DbtngException('Unable to sync the SQLite candidate before publication.');
      }
    }
    finally {
      fclose($stream);
    }
    $this->syncDirectory($partialDirectory);
    if (!rename($partialDirectory, $finalDirectory)) {
      throw new DbtngException('Unable to finalize the SQLite generation directory.');
    }
    $this->syncDirectory(dirname($finalDirectory));
    $target = $context->candidate->publishedIdentifier;
    $directory = dirname($target);
    $this->archiveLegacyPublishedFile($context, $target);
    $temporaryLink = $directory . DIRECTORY_SEPARATOR . '.dbtng-publish-' . $context->candidate->uuid;
    $relativeTarget = 'generations/' . $context->candidate->uuid . '/dbtng.sqlite';
    if (file_exists($temporaryLink) || is_link($temporaryLink) || !symlink($relativeTarget, $temporaryLink)) {
      throw new DbtngException('Unable to create the temporary SQLite publication pointer.');
    }
    $this->failures->hit('during_publish', ['candidate' => $context->candidate->uuid]);
    if (!rename($temporaryLink, $target)) {
      unlink($temporaryLink);
      throw new DbtngException('Atomic SQLite standby publication failed; previous pointer remains active.');
    }
    try {
      $this->syncDirectory($directory);
    }
    catch (\Throwable) {
      $this->loggerFactory->get('dbtng_migrator')->warning('SQLite pointer changed atomically but directory fsync failed.');
    }
    try {
      $this->manifests->markVersionPublished($context->manifestIdentity, $context->candidate->uuid);
      $this->manifests->publishVersion($context->manifestIdentity, $context->candidate->uuid);
    }
    catch (\Throwable) {
      $this->loggerFactory->get('dbtng_migrator')->warning('The SQLite generation pointer was published but its recovery marker could not be refreshed.');
    }
    try {
      $this->applyRetention($context, $target);
    }
    catch (\Throwable) {
      $this->loggerFactory->get('dbtng_migrator')->warning('SQLite generation retention could not remove every expired artifact.');
    }
  }

  private function applyRetention(CandidateContext $context, string $target): void {
    $keep = max(1, (int) ($this->configFactory->get('dbtng_migrator.settings')->get('retention.keep') ?? 3));
    $generations = dirname($target) . DIRECTORY_SEPARATOR . 'generations';
    $active = realpath($target);
    $entries = [];
    foreach (scandir($generations) ?: [] as $entry) {
      if (preg_match('/^[a-f0-9]{32}$/D', $entry) !== 1) {
        continue;
      }
      $directory = $generations . DIRECTORY_SEPARATOR . $entry;
      $file = $directory . DIRECTORY_SEPARATOR . 'dbtng.sqlite';
      if (is_link($directory) || !is_dir($directory) || is_link($file) || !is_file($file)) {
        continue;
      }
      $entries[] = [
        'id' => $entry,
        'dir' => $directory,
        'time' => filemtime($directory) ?: 0,
        'active' => realpath($file) === $active,
      ];
    }
    usort($entries, static fn (array $left, array $right): int => $right['time'] <=> $left['time']);
    $activeFound = FALSE;
    $retainedOld = 0;
    foreach ($entries as $entry) {
      if ($entry['active']) {
        $activeFound = TRUE;
        continue;
      }
      if ($retainedOld < $keep - 1) {
        $retainedOld++;
        continue;
      }
      $generationFiles = [
        'dbtng.sqlite',
        'dbtng.sqlite-wal',
        'dbtng.sqlite-shm',
        'dbtng.sqlite.partial-wal',
        'dbtng.sqlite.partial-shm',
      ];
      foreach ($generationFiles as $filename) {
        $file = $entry['dir'] . DIRECTORY_SEPARATOR . $filename;
        if (is_link($file)) {
          throw new DbtngException('Refusing to apply SQLite generation retention to a linked file.');
        }
        if (is_file($file) && !unlink($file)) {
          throw new DbtngException('Unable to remove an expired SQLite standby generation.');
        }
      }
      if (!rmdir($entry['dir'])) {
        throw new DbtngException('Unable to remove an expired SQLite generation directory.');
      }
      $this->manifests->deleteVersion($context->manifestIdentity, $entry['id']);
    }
    if (!$activeFound) {
      throw new DbtngException('Published SQLite generation is missing from retention inventory.');
    }
  }

  private function assertValidFile(string $path): void {
    if (!is_file($path) || is_link($path) || filesize($path) === 0) {
      throw new DbtngException('SQLite candidate is missing or empty.');
    }
    $handle = new \SQLite3($path, SQLITE3_OPEN_READONLY);
    $handle->enableExceptions(TRUE);
    $handle->createCollation('NOCASE_UTF8', Unicode::strcasecmp(...));
    try {
      if ($handle->querySingle('PRAGMA integrity_check') !== 'ok') {
        throw new DbtngException('SQLite candidate failed integrity_check before publication.');
      }
    }
    finally {
      $handle->close();
    }
  }

  /**
   * Preserves a pre-generation regular standby before swapping its pointer.
   */
  private function archiveLegacyPublishedFile(CandidateContext $context, string $target): void {
    if (is_link($target) || !is_file($target)) {
      return;
    }
    $generation = $context->previousGenerationId;
    if ($generation === NULL || preg_match('/^[a-f0-9]{32}$/D', $generation) !== 1) {
      throw new DbtngException('Unable to identify the old SQLite generation for retention.');
    }
    $archiveDirectory = dirname($target) . DIRECTORY_SEPARATOR . 'generations' . DIRECTORY_SEPARATOR . $generation;
    if (file_exists($archiveDirectory) || !mkdir($archiveDirectory, 0700)) {
      throw new DbtngException('Unable to prepare a private retained SQLite generation.');
    }
    $archivePath = $archiveDirectory . DIRECTORY_SEPARATOR . 'dbtng.sqlite';
    $source = new \SQLite3($target, SQLITE3_OPEN_READONLY);
    $destination = new \SQLite3($archivePath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $source->enableExceptions(TRUE);
    $destination->enableExceptions(TRUE);
    $valid = FALSE;
    try {
      $source->createCollation('NOCASE_UTF8', Unicode::strcasecmp(...));
      $destination->createCollation('NOCASE_UTF8', Unicode::strcasecmp(...));
      if (!$source->backup($destination) || $destination->querySingle('PRAGMA integrity_check') !== 'ok') {
        throw new DbtngException('Unable to preserve a valid snapshot of the previous SQLite standby.');
      }
      $valid = TRUE;
    }
    finally {
      $destination->close();
      $source->close();
      if (!$valid) {
        if (is_file($archivePath) && !is_link($archivePath)) {
          unlink($archivePath);
        }
        if (is_dir($archiveDirectory) && !is_link($archiveDirectory)) {
          rmdir($archiveDirectory);
        }
      }
    }
    chmod($archivePath, 0600);
    chmod($archiveDirectory, 0700);
    $this->syncDirectory($archiveDirectory);
    $this->syncDirectory(dirname($archiveDirectory));
  }

  private function syncDirectory(string $directory): void {
    $stream = @fopen($directory, 'r');
    if ($stream === FALSE) {
      throw new DbtngException('Unable to open a private directory for durability sync.');
    }
    try {
      if (function_exists('fsync') && !fsync($stream)) {
        throw new DbtngException('Directory durability sync failed.');
      }
    }
    finally {
      fclose($stream);
    }
  }

  /**
   * Removes only the two SQLite sidecars for the closed candidate handle.
   */
  private function removeSidecars(string $databasePath): void {
    foreach (['-wal', '-shm'] as $suffix) {
      $path = $databasePath . $suffix;
      if (is_link($path) || (is_file($path) && !unlink($path))) {
        throw new DbtngException('Unable to remove a closed SQLite candidate sidecar.');
      }
    }
  }

}
