<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Snapshot;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Site\Settings;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Manifest\StandbyManifestStore;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\DatabaseRole;
use Drupal\dbtng_migrator\Model\RebuildCandidateState;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\ResolvedDatabase;
use Drupal\dbtng_migrator\Model\StandbyCandidate;
use Drupal\dbtng_migrator\Model\TableNameMap;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;

/**
 * Creates and safely discards engine-specific, isolated rebuild candidates. */
final class CandidateFactory {

  public function __construct(private readonly StandbyManifestStore $manifests) {}

  public function create(
    ResolvedDatabase $standby,
    DatabaseEngine $primaryEngine,
    ReplicationProfile $profile,
    DatabaseInventory $sourceInventory,
    DatabaseInventory $publishedInventory,
    string $uuid,
  ): CandidateContext {
    if ($standby->role !== DatabaseRole::Standby || preg_match('/^[a-f0-9]{32}$/D', $uuid) !== 1) {
      throw new DbtngException('Candidate creation requires a resolved standby and valid rebuild UUID.');
    }
    if ($standby->engine === DatabaseEngine::Sqlite) {
      return $this->createSqlite($standby, $primaryEngine, $profile, $publishedInventory, $uuid);
    }
    return $this->createMysql($standby, $primaryEngine, $profile, $sourceInventory, $publishedInventory, $uuid);
  }

  public function close(CandidateContext $context): void {
    $context->releaseConnection();
  }

  public function discard(CandidateContext $context): void {
    if ($context->candidate->engine === DatabaseEngine::MysqlFamily && $context->candidatePrefix !== NULL) {
      $this->dropCandidateTables($context->connection(), $context->candidatePrefix);
      $this->close($context);
      $this->manifests->deleteVersion($context->manifestIdentity, $context->candidate->uuid);
      return;
    }
    $this->close($context);
    if ($context->partialDirectory !== NULL && is_dir($context->partialDirectory) && !is_link($context->partialDirectory)) {
      $this->removeGeneratedDirectory($context->partialDirectory);
    }
    elseif ($context->finalDirectory !== NULL && is_dir($context->finalDirectory) && !is_link($context->finalDirectory)) {
      $publishedReal = realpath($context->candidate->publishedIdentifier);
      $candidateReal = realpath($context->finalDirectory . DIRECTORY_SEPARATOR . 'dbtng.sqlite');
      if ($publishedReal === FALSE || $candidateReal === FALSE || $publishedReal !== $candidateReal) {
        $this->removeGeneratedDirectory($context->finalDirectory);
      }
    }
    if ($context->candidate->engine === DatabaseEngine::Sqlite) {
      $publishedReal = realpath($context->candidate->publishedIdentifier);
      $candidateReal = $context->finalDirectory === NULL
        ? FALSE
        : realpath($context->finalDirectory . DIRECTORY_SEPARATOR . 'dbtng.sqlite');
      if ($publishedReal === FALSE || $candidateReal === FALSE || $publishedReal !== $candidateReal) {
        $this->manifests->deleteVersion($context->manifestIdentity, $context->candidate->uuid);
      }
    }
  }

  private function createMysql(
    ResolvedDatabase $standby,
    DatabaseEngine $primaryEngine,
    ReplicationProfile $profile,
    DatabaseInventory $sourceInventory,
    DatabaseInventory $publishedInventory,
    string $uuid,
  ): CandidateContext {
    $connection = $standby->connection;
    $short = substr($uuid, 0, 8);
    $prefix = 'dbtngc' . $short . '_';
    $previousManifest = $this->manifests->readCurrent($standby->identity);
    $previousGeneration = $previousManifest['rebuild_id'] ?? $previousManifest['snapshot_id'] ?? NULL;
    if (!is_string($previousGeneration) || preg_match('/^[a-f0-9]{32}$/D', $previousGeneration) !== 1) {
      $previousGeneration = bin2hex(random_bytes(16));
    }
    $archivePrefix = 'dbtngp' . substr($previousGeneration, 0, 8) . '_';
    $nameMap = [];
    foreach ($sourceInventory->tables as $table) {
      $candidateName = $prefix . $table->name;
      if (strlen($candidateName) > 64) {
        throw new DbtngException(sprintf('Candidate identifier for table "%s" would exceed the MariaDB identifier limit.', $table->name));
      }
    }
    $this->cleanStaleMysqlCandidates($connection, $standby->identity);
    $publishedTables = $this->applicationTables($publishedInventory);
    foreach ($sourceInventory->tables as $table) {
      $name = $table->name;
      $nameMap[$name] = $prefix . $name;
    }
    $candidate = new StandbyCandidate(
      DatabaseEngine::MysqlFamily,
      $prefix,
      $standby->identity,
      $uuid,
      $primaryEngine,
      $profile,
      gmdate(DATE_ATOM),
      RebuildCandidateState::Created,
    );
    return new CandidateContext(
      $candidate,
      $connection,
      new TableNameMap($nameMap),
      $publishedTables,
      candidatePrefix: $prefix,
      archivePrefix: $archivePrefix,
      previousPublishedIdentifier: $standby->identity,
      previousGenerationId: $previousGeneration,
      manifestIdentity: $standby->identity,
    );
  }

  private function createSqlite(
    ResolvedDatabase $standby,
    DatabaseEngine $primaryEngine,
    ReplicationProfile $profile,
    DatabaseInventory $publishedInventory,
    string $uuid,
  ): CandidateContext {
    $target = $standby->databasePath;
    $privatePath = Settings::get('file_private_path');
    if (!is_string($target) || $target === '' || $target === ':memory:' || !is_string($privatePath) || $privatePath === '') {
      throw new DbtngException('SQLite rebuild requires an absolute private file-backed standby path.');
    }
    $privateRoot = realpath($privatePath);
    $directory = realpath(dirname($target));
    if ($privateRoot === FALSE || $directory === FALSE
      || !str_starts_with($directory . DIRECTORY_SEPARATOR, rtrim($privateRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
      || (defined('DRUPAL_ROOT') && str_starts_with($directory . DIRECTORY_SEPARATOR, rtrim((string) realpath(DRUPAL_ROOT), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
      throw new DbtngException('SQLite rebuild paths must remain under private storage and outside webroot.');
    }
    $mode = fileperms($directory);
    if ($mode === FALSE || ($mode & 0077) !== 0 || is_link(dirname($target))) {
      throw new DbtngException('SQLite standby directory must be a real owner-private directory.');
    }
    if (is_link($target)) {
      $currentReal = realpath($target);
      if ($currentReal === FALSE || !str_starts_with($currentReal, $directory . DIRECTORY_SEPARATOR . 'generations' . DIRECTORY_SEPARATOR)) {
        throw new DbtngException('SQLite standby pointer resolves outside its private generations directory.');
      }
    }
    elseif (file_exists($target) && !is_file($target)) {
      throw new DbtngException('SQLite standby path is not a regular database file.');
    }
    $generations = $directory . DIRECTORY_SEPARATOR . 'generations';
    if (is_link($generations) || (!is_dir($generations) && !mkdir($generations, 0700) && !is_dir($generations))) {
      throw new DbtngException('Unable to create the private SQLite generation directory.');
    }
    chmod($generations, 0700);
    $currentPath = realpath($target) ?: '';
    $currentGeneration = $this->generationFromPath($currentPath);
    $this->cleanStaleSqliteCandidates($generations, $standby->identity, $currentGeneration);
    $partial = $generations . DIRECTORY_SEPARATOR . $uuid . '.partial';
    $final = $generations . DIRECTORY_SEPARATOR . $uuid;
    if (file_exists($partial) || file_exists($final) || !mkdir($partial, 0700)) {
      throw new DbtngException('Unable to create a unique SQLite rebuild candidate directory.');
    }
    chmod($partial, 0700);
    $candidatePath = $partial . DIRECTORY_SEPARATOR . 'dbtng.sqlite.partial';
    $database = new \SQLite3($candidatePath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $database->enableExceptions(TRUE);
    $database->close();
    chmod($candidatePath, 0600);
    $options = $standby->connection->getConnectionOptions();
    $options['database'] = $candidatePath;
    $key = 'dbtng_candidate_' . $uuid;
    Database::addConnectionInfo($key, 'default', $options);
    try {
      $connection = Database::getConnection('default', $key);
    }
    catch (\Throwable $exception) {
      Database::removeConnection($key);
      $this->removeGeneratedDirectory($partial);
      throw new DbtngException('Unable to open the isolated SQLite rebuild candidate.', 0, $exception);
    }
    $candidate = new StandbyCandidate(
      DatabaseEngine::Sqlite,
      $candidatePath,
      $target,
      $uuid,
      $primaryEngine,
      $profile,
      gmdate(DATE_ATOM),
      RebuildCandidateState::Created,
    );
    return new CandidateContext(
      $candidate,
      $connection,
      new TableNameMap(),
      array_map(static fn ($table): string => $table->name, $publishedInventory->tables),
      $key,
      $partial,
      $candidatePath,
      $final,
      previousPublishedIdentifier: realpath($target) ?: NULL,
      previousGenerationId: $this->generationFromPath(realpath($target) ?: ''),
      manifestIdentity: $standby->identity,
    );
  }

  /**
   * Lists application tables from a physical inventory.
   *
   * @return list<string>
   */
  private function applicationTables(DatabaseInventory $inventory): array {
    return array_map(static fn ($table): string => $table->name, $inventory->tables);
  }

  private function cleanStaleMysqlCandidates(Connection $connection, string $identity): void {
    $statement = $connection->query('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    if (!$statement instanceof StatementInterface) {
      throw new DbtngException('Unable to inspect stale MariaDB candidate objects.');
    }
    $connection->query('SET FOREIGN_KEY_CHECKS = 0');
    try {
      foreach ($statement->fetchAll(FetchAs::Associative) as $object) {
        $name = (string) ($object['TABLE_NAME'] ?? '');
        if (preg_match('/^dbtngc[0-9a-f]{8}_/', $name) !== 1) {
          continue;
        }
        $kind = strtoupper((string) ($object['TABLE_TYPE'] ?? 'BASE TABLE')) === 'VIEW' ? 'VIEW' : 'TABLE';
        $connection->query('DROP ' . $kind . ' ' . SqlIdentifier::quote($connection, $name));
      }
    }
    finally {
      $connection->query('SET FOREIGN_KEY_CHECKS = 1');
    }
    foreach ($this->manifests->listVersions($identity) as $version) {
      if (($version['lifecycle_state'] ?? NULL) === 'candidate' && is_string($version['generation_id'] ?? NULL)) {
        $this->manifests->deleteVersion($identity, $version['generation_id']);
      }
    }
  }

  private function cleanStaleSqliteCandidates(string $directory, string $identity, ?string $currentGeneration): void {
    foreach (scandir($directory) ?: [] as $entry) {
      if (preg_match('/^[a-f0-9]{32}\.partial$/D', $entry) !== 1) {
        continue;
      }
      $path = $directory . DIRECTORY_SEPARATOR . $entry;
      if (is_link($path) || !is_dir($path)) {
        throw new DbtngException('Unexpected SQLite partial candidate filesystem object.');
      }
      $this->removeGeneratedDirectory($path);
    }
    foreach (scandir($directory) ?: [] as $entry) {
      if (preg_match('/^[a-f0-9]{32}$/D', $entry) !== 1 || $entry === $currentGeneration) {
        continue;
      }
      $path = $directory . DIRECTORY_SEPARATOR . $entry;
      if (is_link($path) || !is_dir($path)) {
        throw new DbtngException('Unexpected SQLite generation filesystem object.');
      }
      $manifest = $this->manifests->readVersion($identity, $entry);
      if ($manifest !== NULL && ($manifest['lifecycle_state'] ?? NULL) === 'candidate') {
        $this->removeGeneratedDirectory($path);
        $this->manifests->deleteVersion($identity, $entry);
      }
    }
  }

  private function removeGeneratedDirectory(string $directory): void {
    foreach (scandir($directory) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $path = $directory . DIRECTORY_SEPARATOR . $entry;
      if (is_link($path) || !is_file($path)
        || !in_array($entry, [
          'dbtng.sqlite.partial',
          'dbtng.sqlite.partial-wal',
          'dbtng.sqlite.partial-shm',
          'dbtng.sqlite',
          'dbtng.sqlite-wal',
          'dbtng.sqlite-shm',
        ], TRUE)) {
        throw new DbtngException('Refusing to recursively remove unexpected SQLite candidate content.');
      }
      unlink($path);
    }
    rmdir($directory);
  }

  private function dropCandidateTables(Connection $connection, string $prefix): void {
    $statement = $connection->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    if (!$statement instanceof StatementInterface) {
      return;
    }
    $connection->query('SET FOREIGN_KEY_CHECKS = 0');
    try {
      foreach ($statement->fetchAll(FetchAs::Associative) as $row) {
        $name = (string) ($row['TABLE_NAME'] ?? '');
        if (str_starts_with($name, $prefix)) {
          $connection->query('DROP TABLE ' . SqlIdentifier::quote($connection, $name));
        }
      }
    }
    finally {
      $connection->query('SET FOREIGN_KEY_CHECKS = 1');
    }
  }

  private function generationFromPath(string $path): ?string {
    $parent = basename(dirname($path));
    return preg_match('/^[a-f0-9]{32}$/D', $parent) === 1 ? $parent : NULL;
  }

}
