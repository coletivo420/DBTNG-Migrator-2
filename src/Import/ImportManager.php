<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Import;

use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Site\Settings;
use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\ImportManagerInterface;
use Drupal\dbtng_migrator\Destination\DestinationPreparationManager;
use Drupal\dbtng_migrator\Destination\DestinationStateInspectionManager;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\ImportRequest;
use Drupal\dbtng_migrator\Model\ReplicationDecision;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\SnapshotManifest;
use Drupal\dbtng_migrator\Policy\CleanReplicationPolicy;
use Drupal\dbtng_migrator\Schema\MysqlSchemaBuilder;
use Drupal\dbtng_migrator\Schema\PortabilityAnalyzer;
use Drupal\dbtng_migrator\Schema\SchemaIntrospectionManager;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;
use Drupal\dbtng_migrator\Schema\SqliteSchemaBuilder;
use Drupal\dbtng_migrator\Model\DestinationState;

/**
 * Performs validated bidirectional cross-engine logical imports. */
final class ImportManager implements ImportManagerInterface {

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly SchemaIntrospectionManager $introspector,
    private readonly DestinationStateInspectionManager $destinationInspector,
    private readonly PortabilityAnalyzer $portabilityAnalyzer,
    private readonly ImportPortabilityGate $portabilityGate,
    private readonly DestinationPreparationManager $preparation,
    private readonly MysqlSchemaBuilder $mysqlBuilder,
    private readonly SqliteSchemaBuilder $sqliteBuilder,
    private readonly RowTransfer $transfer,
    private readonly CleanReplicationPolicy $cleanPolicy,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function import(ImportRequest $request): SnapshotManifest {
    $initial = $this->resolver->resolve();
    $this->assertTopology($request, $initial->topology);
    if (!$initial->topology->supportsProfile($request->profile)) {
      throw new DbtngException('The requested profile is not supported for this standby engine.');
    }
    if ($initial->primary->identity === $initial->standby->identity) {
      throw new DbtngException('Logical import refused because primary and standby resolve to the same physical database.');
    }

    [$source, $sourceKey] = $this->openDedicatedConnection($initial->primary->connection);
    $transactionOpen = FALSE;
    $candidateKey = NULL;
    $candidate = NULL;
    $candidatePath = NULL;
    $standbyHash = NULL;
    try {
      $this->beginConsistentSnapshot($source, $initial->primary->engine);
      $transactionOpen = TRUE;
      $inventory = $this->introspector->inspect($source);
      if ($initial->primary->engine === DatabaseEngine::MysqlFamily) {
        foreach ($inventory->tables as $table) {
          if (strtolower((string) $table->storageEngine) !== 'innodb') {
            throw new DbtngException(sprintf('Consistent import is blocked by non-InnoDB source table "%s".', $table->name));
          }
        }
      }
      $report = $this->portabilityAnalyzer->analyze($inventory);
      $this->portabilityGate->assertImportable($inventory, $initial->standby->engine, $report);

      // Preserve a populated SQLite standby until the validated candidate can
      // be atomically published. MySQL clear follows the explicit policy now.
      $preparation = $this->preparation->prepare(
        $initial->topology,
        $request->nonEmptyPolicy,
        $initial->standby->engine === DatabaseEngine::Sqlite,
      );
      $current = $this->resolver->resolve();
      if ($current->primary->identity !== $initial->primary->identity || $current->standby->identity !== $initial->standby->identity) {
        throw new DbtngException('Runtime database identities changed while import was being prepared.');
      }

      $candidate = $current->standby->connection;
      if ($current->standby->engine === DatabaseEngine::Sqlite) {
        [$candidate, $candidateKey, $candidatePath] = $this->createSqliteCandidate($current->standby->connection);
      }
      elseif ($this->destinationInspector->inspect($candidate)->state !== DestinationState::Empty) {
        throw new DbtngException('MySQL standby was not empty after the configured preparation policy.');
      }

      if ($candidate->driver() === 'sqlite') {
        $candidate->query('PRAGMA foreign_keys = OFF');
        $this->sqliteBuilder->createTables($candidate, $inventory);
      }
      else {
        $this->mysqlBuilder->createTables($candidate, $inventory);
      }

      $expectedRows = [];
      foreach ($inventory->tables as $table) {
        $expectedRows[$table->name] = $request->profile === ReplicationProfile::Clean
          && $this->cleanPolicy->tableDecision($table) === ReplicationDecision::SchemaOnly
          ? 0
          : $this->countRows($source, $table->name);
      }
      $settings = $this->configFactory->get('dbtng_migrator.settings');
      $batchRows = max(1, (int) ($settings->get('snapshot.batch_rows') ?? 500));
      $batchBytes = max(1024, (int) ($settings->get('snapshot.batch_bytes') ?? 4194304));
      $transferStats = $this->transfer->transfer($source, $candidate, $inventory, $request->profile, $batchRows, $batchBytes);

      $indexWarnings = 0;
      if ($candidate->driver() === 'mysql') {
        $indexWarnings = $this->mysqlBuilder->createIndexesAndConstraints($candidate, $inventory);
      }
      else {
        $this->sqliteBuilder->createIndexes($candidate, $inventory);
        $candidate->query('PRAGMA foreign_keys = ON');
      }
      $this->validateSchema($candidate, $inventory);
      $this->validateRows($candidate, $inventory, $expectedRows);
      $this->validateEngineIntegrity($candidate);

      $tableCount = count($inventory->tables);
      $rowCount = array_sum($expectedRows);
      $peakMemory = memory_get_peak_usage(TRUE);
      if ($current->standby->engine === DatabaseEngine::Sqlite) {
        Database::removeConnection($candidateKey);
        $candidate = NULL;
        Database::closeConnection(NULL, $current->standby->connectionKey);
        if (!rename($candidatePath, (string) $current->standby->databasePath)) {
          throw new DbtngException('Validated SQLite import candidate could not be atomically published.');
        }
        if (!chmod((string) $current->standby->databasePath, 0600)) {
          throw new DbtngException('Unable to protect the published SQLite standby database.');
        }
        $standbyHash = hash_file('sha256', (string) $current->standby->databasePath) ?: NULL;
        $candidatePath = NULL;
        $published = $this->resolver->resolve();
        if ($this->destinationInspector->inspect($published->standby->connection)->state !== DestinationState::NonEmpty) {
          throw new DbtngException('Published SQLite standby did not contain application schema.');
        }
      }

      return new SnapshotManifest(
        'dbtng-logical-import-v1',
        bin2hex(random_bytes(16)),
        gmdate(DATE_ATOM),
        $initial->primary->engine,
        $initial->standby->engine,
        $request->profile,
        TRUE,
        $request->profile === ReplicationProfile::Full,
        $tableCount,
        $rowCount,
        $standbyHash,
        $transferStats['bytes'],
        $peakMemory,
        $report->countBySeverity('warning') + $indexWarnings,
        $preparation->safetyBackup?->path,
        $preparation->safetyBackup?->sha256,
        $preparation->previousState->value,
        $preparation->policy->value,
      );
    }
    catch (\Throwable $exception) {
      if ($exception instanceof DbtngException) {
        throw $exception;
      }
      throw new DbtngException('Logical import failed; the standby was not marked initialized.', 0, $exception);
    }
    finally {
      if ($transactionOpen) {
        try {
          $source->query('ROLLBACK');
        }
        catch (\Throwable) {
          // The dedicated source connection is removed immediately below.
        }
      }
      Database::removeConnection($sourceKey);
      if ($candidateKey !== NULL) {
        Database::removeConnection($candidateKey);
      }
      if ($candidatePath !== NULL && is_file($candidatePath)) {
        unlink($candidatePath);
      }
    }
  }

  /**
   * Opens a dedicated connection for the consistent source snapshot.
   *
   * @return array{0: Connection, 1: string}
   *   The isolated connection and its temporary registry key.
   */
  private function openDedicatedConnection(Connection $reference): array {
    $key = 'dbtng_snapshot_' . bin2hex(random_bytes(8));
    Database::addConnectionInfo($key, 'default', $reference->getConnectionOptions());
    try {
      return [Database::getConnection('default', $key), $key];
    }
    catch (\Throwable $exception) {
      Database::removeConnection($key);
      throw new DbtngException('Unable to open an isolated source snapshot connection.', 0, $exception);
    }
  }

  private function beginConsistentSnapshot(Connection $source, DatabaseEngine $engine): void {
    try {
      if ($engine === DatabaseEngine::MysqlFamily) {
        $source->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $source->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
      }
      else {
        $source->query('BEGIN DEFERRED TRANSACTION');
        // Establish the SQLite snapshot before destination work begins.
        $statement = $source->query('SELECT COUNT(*) FROM sqlite_schema');
        if (!$statement instanceof StatementInterface) {
          throw new DbtngException('SQLite source snapshot could not read its schema.');
        }
        $statement->fetchField();
      }
    }
    catch (\Throwable $exception) {
      throw new DbtngException('Unable to establish a consistent read-only source snapshot.', 0, $exception);
    }
  }

  /**
   * Creates an isolated, private SQLite file for the candidate import.
   *
   * @return array{0: Connection, 1: string, 2: string}
   *   Candidate connection, temporary registry key and candidate path.
   */
  private function createSqliteCandidate(Connection $standby): array {
    $target = (string) ($standby->getConnectionOptions()['database'] ?? '');
    $privateRoot = Settings::get('file_private_path');
    if ($target === '' || $target === ':memory:' || !is_string($privateRoot) || $privateRoot === ''
      || is_link($target) || is_link(dirname($target))) {
      throw new DbtngException('SQLite import requires a regular private file-backed standby path.');
    }
    $directory = realpath(dirname($target));
    $private = realpath($privateRoot);
    if ($directory === FALSE || $private === FALSE || !str_starts_with($directory . DIRECTORY_SEPARATOR, rtrim($private, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
      || (defined('DRUPAL_ROOT') && str_starts_with($directory . DIRECTORY_SEPARATOR, rtrim((string) realpath(DRUPAL_ROOT), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
      throw new DbtngException('SQLite import candidate must be under private storage and outside webroot.');
    }
    $candidate = tempnam($directory, '.dbtng-import-');
    if ($candidate === FALSE || !chmod($candidate, 0600)) {
      throw new DbtngException('Unable to create a private SQLite import candidate.');
    }
    $database = new \SQLite3($candidate, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $database->enableExceptions(TRUE);
    $database->close();
    $options = $standby->getConnectionOptions();
    $options['database'] = $candidate;
    $key = 'dbtng_candidate_' . bin2hex(random_bytes(8));
    Database::addConnectionInfo($key, 'default', $options);
    try {
      return [Database::getConnection('default', $key), $key, $candidate];
    }
    catch (\Throwable $exception) {
      Database::removeConnection($key);
      unlink($candidate);
      throw new DbtngException('Unable to open the isolated SQLite import candidate.', 0, $exception);
    }
  }

  private function countRows(Connection $connection, string $table): int {
    $statement = $connection->query('SELECT COUNT(*) FROM ' . SqlIdentifier::quote($connection, $table));
    if (!$statement instanceof StatementInterface) {
      throw new DbtngException('Unable to count a source table inside the consistent snapshot.');
    }
    return (int) $statement->fetchField();
  }

  private function validateSchema(Connection $destination, DatabaseInventory $expected): void {
    $actual = $this->introspector->inspect($destination);
    $expectedNames = array_map(static fn ($table): string => $table->name, $expected->tables);
    $actualNames = array_map(static fn ($table): string => $table->name, $actual->tables);
    sort($expectedNames);
    sort($actualNames);
    if ($expectedNames !== $actualNames) {
      throw new DbtngException('Destination schema validation failed: table names differ from the source inventory.');
    }
    $expectedByName = [];
    foreach ($expected->tables as $table) {
      $expectedByName[$table->name] = $table;
    }
    foreach ($actual->tables as $table) {
      $source = $expectedByName[$table->name];
      $sourceColumns = array_map(static fn ($column): string => $column->name, array_filter($source->columns, static fn ($column): bool => !$column->hidden));
      $actualColumns = array_map(static fn ($column): string => $column->name, array_filter($table->columns, static fn ($column): bool => !$column->hidden));
      if ($sourceColumns !== $actualColumns || $source->primaryKey !== $table->primaryKey) {
        throw new DbtngException(sprintf('Destination schema validation failed for table "%s".', $table->name));
      }
    }
  }

  /**
   * Compares destination row totals against the source snapshot.
   *
   * @param array<string, int> $expected
   *   Expected count per physical table.
   */
  private function validateRows(Connection $destination, DatabaseInventory $inventory, array $expected): void {
    foreach ($inventory->tables as $table) {
      $actual = $this->countRows($destination, $table->name);
      if ($actual !== $expected[$table->name]) {
        throw new DbtngException(sprintf('Row-count validation failed for table "%s".', $table->name));
      }
    }
  }

  private function validateEngineIntegrity(Connection $destination): void {
    if (strtolower($destination->driver()) === 'sqlite') {
      $statement = $destination->query('PRAGMA integrity_check');
      if (!$statement instanceof StatementInterface) {
        throw new DbtngException('SQLite integrity_check did not return a result.');
      }
      $integrity = $statement->fetchField();
      if ($integrity !== 'ok') {
        throw new DbtngException('SQLite import failed PRAGMA integrity_check.');
      }
      $statement = $destination->query('PRAGMA foreign_key_check');
      if ($statement instanceof StatementInterface && $statement->fetch(FetchAs::Associative) !== FALSE) {
        throw new DbtngException('SQLite import failed PRAGMA foreign_key_check.');
      }
    }
  }

  private function assertTopology(ImportRequest $request, DatabaseTopology $actual): void {
    $expected = $request->topology;
    if ($expected->primaryEngine !== $actual->primaryEngine || $expected->standbyEngine !== $actual->standbyEngine
      || $expected->primaryConnectionKey !== $actual->primaryConnectionKey || $expected->standbyConnectionKey !== $actual->standbyConnectionKey) {
      throw new DbtngException('Import request does not match the configured primary/standby topology.');
    }
  }

}
