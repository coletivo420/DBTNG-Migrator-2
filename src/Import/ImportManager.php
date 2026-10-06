<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Import;

use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Site\Settings;
use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\ImportManagerInterface;
use Drupal\dbtng_migrator\Destination\DestinationPreparationManager;
use Drupal\dbtng_migrator\Destination\DestinationStateInspectionManager;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\ImportRequest;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\SnapshotManifest;
use Drupal\dbtng_migrator\Manifest\StandbyManifestStore;
use Drupal\dbtng_migrator\Schema\SchemaIntrospectionManager;
use Drupal\dbtng_migrator\Model\DestinationState;
use Drupal\dbtng_migrator\Operation\OperationLock;

/**
 * Performs validated bidirectional cross-engine logical imports. */
final class ImportManager implements ImportManagerInterface {

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly SchemaIntrospectionManager $introspector,
    private readonly DestinationStateInspectionManager $destinationInspector,
    private readonly DestinationPreparationManager $preparation,
    private readonly StandbyManifestStore $manifestStore,
    private readonly LogicalSnapshotBuilder $snapshotBuilder,
    private readonly OperationLock $operationLock,
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
    $operationLock = $this->operationLock->acquire('import');

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

      $build = $this->snapshotBuilder->build($source, $candidate, $inventory, $request->profile);
      $tableCount = $build->tableCount;
      $rowCount = $build->rowCount;
      $peakMemory = $build->peakMemoryBytes;
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

      $manifest = new SnapshotManifest(
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
        $build->bytesTransferred,
        $peakMemory,
        $build->portabilityWarnings,
        $preparation->safetyBackup?->path,
        $preparation->safetyBackup?->sha256,
        $preparation->previousState->value,
        $preparation->policy->value,
      );
      $manifestPath = $this->manifestStore->write($initial->standby->identity, [
        'snapshot_id' => $manifest->snapshotId,
        'created_at' => $manifest->createdAt,
        'source_role' => 'primary',
        'source_engine' => $manifest->primaryEngine->value,
        'destination_role' => 'standby',
        'destination_engine' => $manifest->standbyEngine->value,
        'profile' => $manifest->profile->value,
        'portable' => $manifest->portable,
        'activatable' => $manifest->activatable,
        'table_count' => $manifest->tableCount,
        'row_count' => $manifest->rowCount,
        'sha256' => $manifest->sha256,
        'bytes_transferred' => $manifest->bytesTransferred,
        'peak_memory_bytes' => $manifest->peakMemoryBytes,
        'portability_warnings' => $manifest->portabilityWarnings,
        'safety_backup_sha256' => $manifest->safetyBackupSha256,
        'destination_policy' => $manifest->destinationPolicy,
        'previous_destination_state' => $manifest->previousDestinationState,
        'validation' => ['schema' => 'pass', 'row_counts' => 'pass', 'engine_integrity' => 'pass'],
      ]);
      return new SnapshotManifest(
        $manifest->format,
        $manifest->snapshotId,
        $manifest->createdAt,
        $manifest->primaryEngine,
        $manifest->standbyEngine,
        $manifest->profile,
        $manifest->portable,
        $manifest->activatable,
        $manifest->tableCount,
        $manifest->rowCount,
        $manifest->sha256,
        $manifest->bytesTransferred,
        $manifest->peakMemoryBytes,
        $manifest->portabilityWarnings,
        $manifest->safetyBackupPath,
        $manifest->safetyBackupSha256,
        $manifest->previousDestinationState,
        $manifest->destinationPolicy,
        $manifestPath,
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
      $operationLock->release();
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
    $directoryMode = fileperms($directory);
    if ($directoryMode === FALSE || ($directoryMode & 0077) !== 0) {
      throw new DbtngException('SQLite import directory must be owner-private (0700 or stricter).');
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

  private function assertTopology(ImportRequest $request, DatabaseTopology $actual): void {
    $expected = $request->topology;
    if ($expected->primaryEngine !== $actual->primaryEngine || $expected->standbyEngine !== $actual->standbyEngine
      || $expected->primaryConnectionKey !== $actual->primaryConnectionKey || $expected->standbyConnectionKey !== $actual->standbyConnectionKey) {
      throw new DbtngException('Import request does not match the configured primary/standby topology.');
    }
  }

}
