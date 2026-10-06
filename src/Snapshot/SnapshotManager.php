<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Snapshot;

use Drupal\dbtng_migrator\Model\ReplicationDecision;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\FailureInjectorInterface;
use Drupal\dbtng_migrator\Contract\RebuildManagerInterface;
use Drupal\dbtng_migrator\Contract\SnapshotManagerInterface;
use Drupal\dbtng_migrator\Contract\SnapshotPublisherInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Import\LogicalSnapshotBuilder;
use Drupal\dbtng_migrator\Manifest\StandbyManifestStore;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\RebuildCandidateState;
use Drupal\dbtng_migrator\Model\RebuildResult;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\SnapshotManifest;
use Drupal\dbtng_migrator\Model\SnapshotRequest;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Operation\OperationLock;
use Drupal\dbtng_migrator\Policy\CleanReplicationPolicy;
use Drupal\dbtng_migrator\Reconciliation\TableContentFingerprint;
use Drupal\dbtng_migrator\Schema\SchemaIntrospectionManager;
use Drupal\dbtng_migrator\Schema\SchemaFingerprint;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;

/**
 * Builds, validates and publishes an isolated replacement standby. */
final class SnapshotManager implements RebuildManagerInterface, SnapshotManagerInterface {

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly SchemaIntrospectionManager $introspector,
    private readonly LogicalSnapshotBuilder $builder,
    private readonly CandidateFactory $candidates,
    private readonly SnapshotPublisherInterface $sqlitePublisher,
    private readonly SnapshotPublisherInterface $mysqlPublisher,
    private readonly StandbyManifestStore $manifests,
    private readonly OperationLock $locks,
    private readonly FailureInjectorInterface $failures,
    private readonly SchemaFingerprint $fingerprints,
    private readonly CleanReplicationPolicy $cleanPolicy,
    private readonly TableContentFingerprint $contentFingerprint,
  ) {}

  public function rebuild(SnapshotRequest $request): RebuildResult {
    $topology = $this->resolver->resolve();
    if ($request->topology->primaryConnectionKey !== $topology->topology->primaryConnectionKey
      || $request->topology->standbyConnectionKey !== $topology->topology->standbyConnectionKey
      || $request->topology->primaryEngine !== $topology->primary->engine
      || $request->topology->standbyEngine !== $topology->standby->engine) {
      throw new DbtngException('Rebuild request does not match the resolved runtime topology.');
    }
    if (!$topology->topology->supportsProfile($request->profile)) {
      throw new DbtngException('The requested rebuild profile is unsupported for this direction.');
    }
    $lock = $this->locks->acquire('rebuild');
    $uuid = bin2hex(random_bytes(16));
    $sourceKey = 'dbtng_rebuild_source_' . $uuid;
    $source = NULL;
    $context = NULL;
    $snapshotOpen = FALSE;
    $published = FALSE;
    $started = hrtime(TRUE);
    try {
      $source = $this->openDedicated($topology->primary->connection, $sourceKey);
      $this->beginSnapshot($source, $topology->primary->engine);
      $snapshotOpen = TRUE;
      $sourceInventory = $this->introspector->inspect($source);
      if ($sourceInventory->objects !== []) {
        throw new DbtngException('Rebuild is blocked by source views, triggers, routines, or other non-table objects.');
      }
      $publishedInventory = $this->introspector->inspect($topology->standby->connection);
      if ($publishedInventory->objects !== []) {
        throw new DbtngException('Rebuild is blocked while the published standby contains unsupported database objects.');
      }
      $context = $this->candidates->create($topology->standby, $topology->primary->engine, $request->profile, $sourceInventory, $publishedInventory, $uuid);
      $this->failures->hit('after_candidate_create', ['candidate' => $uuid]);
      $context->candidate = $context->candidate->withState(RebuildCandidateState::Building);
      $build = $this->builder->build(
        $source,
        $context->connection(),
        $sourceInventory,
        $request->profile,
        $context->tableNames,
        $context->candidatePrefix === NULL ? NULL : substr($uuid, 0, 8),
        $context->candidate->engine === DatabaseEngine::MysqlFamily ? array_values($context->tableNames->all()) : NULL,
        $uuid,
      );
      $context->candidate = $context->candidate->withBuildMetrics(
        $build->tableCount,
        $build->rowCount,
        $build->portabilityWarnings,
      );
      $artifactHash = NULL;
      if ($context->candidate->engine === DatabaseEngine::Sqlite && $context->candidatePath !== NULL) {
        $context->connection()->query('PRAGMA wal_checkpoint(TRUNCATE)');
        $artifactHash = hash_file('sha256', $context->candidatePath) ?: NULL;
        if ($artifactHash === NULL) {
          throw new DbtngException('Unable to calculate the SQLite candidate artifact checksum.');
        }
      }
      if ($context->candidate->engine === DatabaseEngine::MysqlFamily) {
        $stateTable = $context->candidatePrefix . 'snapshot_state';
        $context->connection()->query(
          'CREATE TABLE ' . SqlIdentifier::quote($context->connection(), $stateTable)
          . ' (generation_id CHAR(32) NOT NULL PRIMARY KEY, profile VARCHAR(16) NOT NULL, schema_fingerprint CHAR(64) NOT NULL, created_at VARCHAR(32) NOT NULL) ENGINE=InnoDB',
        );
        $context->connection()->query(
          'INSERT INTO ' . SqlIdentifier::quote($context->connection(), $stateTable)
          . ' (generation_id, profile, schema_fingerprint, created_at) VALUES (:generation, :profile, :fingerprint, :created)',
          [
            ':generation' => $uuid,
            ':profile' => $request->profile->value,
            ':fingerprint' => $build->schemaFingerprint,
            ':created' => gmdate(DATE_ATOM),
          ],
        );
      }
      $source->query('ROLLBACK');
      $snapshotOpen = FALSE;

      // Fence writes briefly to close the no-CDC publication race.
      $this->acquireFinalBarrier($source, $topology->primary->engine, $sourceInventory);
      try {
        $finalInventory = $this->introspector->inspect($source);
        if ($this->fingerprints->calculate($sourceInventory)
          !== $this->fingerprints->calculate($finalInventory)) {
          throw new DbtngException('Source schema changed during rebuild; candidate was rejected and rebuild is required.');
        }
        $this->assertCountsUnchanged($source, $context->connection(), $sourceInventory, $build->expectedRows, $context);
        $this->failures->hit('before_publish', ['candidate' => $uuid]);
        $manifest = new SnapshotManifest(
          'dbtng-rebuild-v1', $uuid, gmdate(DATE_ATOM), $topology->primary->engine,
          $topology->standby->engine, $request->profile, TRUE,
          $request->profile === ReplicationProfile::Full, $build->tableCount,
          $build->rowCount, $artifactHash, $build->bytesTransferred, $build->peakMemoryBytes,
          $build->portabilityWarnings, rebuildId: $uuid,
          schemaFingerprint: $build->schemaFingerprint, reconciliationStatus: 'MATCH',
          candidateIdentifier: $context->candidate->candidateIdentifier,
          publishedIdentifier: $context->candidate->publishedIdentifier,
          previousPublishedIdentifier: $context->previousPublishedIdentifier,
          validationStatus: 'PASS', fullFidelity: $request->profile === ReplicationProfile::Full,
          intentionalExclusions: $request->profile === ReplicationProfile::Clean
            ? ['cache_*', 'sessions', 'semaphore', 'batch', 'watchdog']
            : [],
        );
        $this->manifests->writeVersion($context->manifestIdentity, $uuid, [
          'rebuild_id' => $uuid, 'created_at' => $manifest->createdAt,
          'lifecycle_state' => 'candidate',
          'primary_engine' => $manifest->primaryEngine->value,
          'standby_engine' => $manifest->standbyEngine->value, 'profile' => $manifest->profile->value,
          'schema_fingerprint' => $manifest->schemaFingerprint, 'table_count' => $manifest->tableCount,
          'row_count' => $manifest->rowCount, 'portability_warnings' => $manifest->portabilityWarnings,
          'artifact_sha256' => $manifest->sha256,
          'validation_status' => 'PASS', 'reconciliation_status' => 'MATCH',
          'candidate_identifier' => $manifest->candidateIdentifier,
          'published_identifier' => $manifest->publishedIdentifier,
          'previous_published_identifier' => $manifest->previousPublishedIdentifier,
          'activatable' => $manifest->activatable, 'full_fidelity' => $manifest->fullFidelity,
          'intentional_exclusions' => $manifest->intentionalExclusions,
          'peak_memory_bytes' => $manifest->peakMemoryBytes, 'batch_count' => $build->batchCount,
        ]);
        $context->candidate = $context->candidate->withState(RebuildCandidateState::Publishing, TRUE);
        if ($topology->standby->engine === DatabaseEngine::Sqlite) {
          $this->sqlitePublisher->publish($context, $manifest);
        }
        else {
          $this->mysqlPublisher->publish($context, $manifest);
        }
        $published = TRUE;
      }
      finally {
        $this->releaseFinalBarrier($source, $topology->primary->engine);
      }
      return new RebuildResult(
        $manifest, $uuid, $manifest->candidateIdentifier ?? '', $manifest->publishedIdentifier ?? '',
        $manifest->previousPublishedIdentifier, RebuildCandidateState::Published, 'MATCH',
        (int) ((hrtime(TRUE) - $started) / 1_000_000), $build->batchCount,
      );
    }
    catch (\Throwable $exception) {
      if (!$published && $context !== NULL) {
        try {
          $this->candidates->discard($context);
        }
        catch (\Throwable) {
          // Private partial state remains available for safe cleanup.
        }
      }
      if ($exception instanceof DbtngException) {
        throw $exception;
      }
      throw new DbtngException(
        'Standby rebuild failed; the old published standby was retained.',
        0,
        $exception,
      );
    }
    finally {
      if ($snapshotOpen && $source instanceof Connection) {
        try {
          $source->query('ROLLBACK');
        }
        catch (\Throwable) {
        }
      }
      if ($context !== NULL) {
        try {
          $this->candidates->close($context);
        }
        catch (\Throwable) {
        }
      }
      if ($source instanceof Connection) {
        Database::removeConnection($sourceKey);
      }
      $lock->release();
    }
  }

  public function create(SnapshotRequest $request): SnapshotManifest {
    return $this->rebuild($request)->manifest;
  }

  private function openDedicated(Connection $reference, string $key): Connection {
    Database::addConnectionInfo($key, 'default', $reference->getConnectionOptions());
    return Database::getConnection('default', $key);
  }

  private function beginSnapshot(Connection $source, DatabaseEngine $engine): void {
    if ($engine === DatabaseEngine::MysqlFamily) {
      $source->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
      $source->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
      return;
    }
    $source->query('BEGIN DEFERRED TRANSACTION');
    $statement = $source->query('SELECT COUNT(*) FROM sqlite_schema');
    if ($statement === NULL) {
      throw new DbtngException('Unable to establish the SQLite source snapshot.');
    }
    $statement->fetchField();
  }

  private function acquireFinalBarrier(Connection $source, DatabaseEngine $engine, DatabaseInventory $inventory): void {
    if ($engine === DatabaseEngine::Sqlite) {
      $source->query('BEGIN IMMEDIATE TRANSACTION');
      return;
    }
    $names = [];
    foreach ($inventory->tables as $table) {
      $names[] = SqlIdentifier::quote($source, $table->name) . ' READ';
    }
    if ($names !== []) {
      $source->query('LOCK TABLES ' . implode(', ', $names));
    }
  }

  private function releaseFinalBarrier(Connection $source, DatabaseEngine $engine): void {
    if ($engine === DatabaseEngine::Sqlite) {
      $source->query('ROLLBACK');
    }
    else {
      $source->query('UNLOCK TABLES');
    }
  }

  /**
   * Confirms candidate row counts against the source while writes are fenced.
   *
   * @param array<string, int> $expected
   *   Projected row counts by physical table name.
   */
  private function assertCountsUnchanged(Connection $source, Connection $candidate, DatabaseInventory $inventory, array $expected, CandidateContext $context): void {
    foreach ($inventory->tables as $table) {
      $sourceStatement = $source->query('SELECT COUNT(*) FROM ' . SqlIdentifier::quote($source, $table->name));
      $candidateStatement = NULL;
      if ($sourceStatement === NULL) {
        throw new DbtngException('Unable to count rows under the final source barrier.');
      }
      $sourceCount = (int) $sourceStatement->fetchField();
      if (!($context->candidate->profile === ReplicationProfile::Clean && $this->cleanPolicy->tableDecision($table) === ReplicationDecision::SchemaOnly)
        && $sourceCount !== ($expected[$table->name] ?? -1)) {
        throw new DbtngException(sprintf('Source table "%s" changed after snapshot; old standby retained and rebuild required.', $table->name));
      }
      $destinationName = $context->tableNames->destination($table->name);
      $candidateStatement = $candidate->query('SELECT COUNT(*) FROM ' . SqlIdentifier::quote($candidate, $destinationName));
      if ($candidateStatement === NULL) {
        throw new DbtngException('Unable to count rows in the candidate under the final barrier.');
      }
      $destinationCount = (int) $candidateStatement->fetchField();
      if ($destinationCount !== ($expected[$table->name] ?? -1)) {
        throw new DbtngException(sprintf('Candidate table "%s" differs during final parity barrier.', $table->name));
      }
      if (!($context->candidate->profile === ReplicationProfile::Clean
        && $this->cleanPolicy->tableDecision($table) === ReplicationDecision::SchemaOnly)) {
        $sourceDigest = $this->contentFingerprint->calculate($source, $table);
        $candidateTable = new TableDefinition(
          $destinationName,
          $table->columns,
          $table->primaryKey,
        );
        $candidateDigest = $this->contentFingerprint->calculate($candidate, $candidateTable);
        if ($sourceDigest !== $candidateDigest) {
          throw new DbtngException(sprintf('Candidate content for "%s" changed before publication.', $table->name));
        }
      }
    }
  }

}
