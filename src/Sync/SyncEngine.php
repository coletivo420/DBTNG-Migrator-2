<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\ChangeCaptureInterface;
use Drupal\dbtng_migrator\Contract\FailureInjectorInterface;
use Drupal\dbtng_migrator\Contract\StandbyChangeApplierInterface;
use Drupal\dbtng_migrator\Contract\SyncEngineInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Exception\SyncBlockedException;
use Drupal\dbtng_migrator\Exception\SyncRebuildRequiredException;
use Drupal\dbtng_migrator\Exception\SyncTransientException;
use Drupal\dbtng_migrator\Manifest\StandbyManifestStore;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\DirtyIdentity;
use Drupal\dbtng_migrator\Model\ReplicationDecision;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\SyncBatchResult;
use Drupal\dbtng_migrator\Model\SyncResultStatus;
use Drupal\dbtng_migrator\Model\SyncStatus;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Operation\OperationLock;
use Drupal\dbtng_migrator\Policy\CleanReplicationPolicy;
use Drupal\dbtng_migrator\Schema\SchemaFingerprint;
use Drupal\dbtng_migrator\Schema\SchemaIntrospectionManager;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;

/**
 * Applies bounded dirty identities from primary current state to standby. */
final class SyncEngine implements SyncEngineInterface {

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly SchemaIntrospectionManager $introspector,
    private readonly ChangeCaptureInterface $capture,
    private readonly DirtyEventReducer $reducer,
    private readonly PrimaryRowReader $rowReader,
    private readonly MysqlStandbyChangeApplier $mysqlApplier,
    private readonly SqliteStandbyChangeApplier $sqliteApplier,
    private readonly TableReconciler $tableReconciler,
    private readonly CleanReplicationPolicy $cleanPolicy,
    private readonly StandbyManifestStore $manifests,
    private readonly SchemaFingerprint $fingerprints,
    private readonly OperationLock $locks,
    private readonly FailureInjectorInterface $failures,
  ) {}

  public function syncOnce(int $limit = 500): SyncBatchResult {
    if ($limit < 1 || $limit > 5000) {
      throw new \InvalidArgumentException('Sync batch limit must be between 1 and 5000 events.');
    }
    $lock = $this->locks->acquire('sync_once');
    $started = hrtime(TRUE);
    if (function_exists('memory_reset_peak_usage')) {
      memory_reset_peak_usage();
    }
    $memoryStart = memory_get_usage(TRUE);
    try {
      $topology = $this->resolver->resolve();
      $inventory = $this->introspector->inspect($topology->primary->connection);
      $standbyInventory = $this->introspector->inspect($topology->standby->connection);
      $manifest = $this->validatedManifest($topology->standby->connection, $topology->standby->engine, $topology->standby->identity, $topology->standby->databasePath);
      $this->assertBaseline($topology->primary->engine, $topology->standby->engine, $topology->profile, $inventory, $manifest);
      $this->assertStandbySchema($inventory, $standbyInventory);
      if ($inventory->objects !== []) {
        throw new SyncRebuildRequiredException('Sync blocked: primary contains unsupported schema objects; rebuild required.');
      }
      if ($standbyInventory->objects !== []) {
        throw new SyncRebuildRequiredException('Sync blocked: standby contains unsupported schema objects; rebuild required.');
      }
      $captureStatus = $this->capture->status();
      if (!$captureStatus->healthy || $captureStatus->engine !== $topology->primary->engine) {
        throw new SyncBlockedException('Sync blocked: primary change capture is missing or unhealthy.');
      }
      $events = $this->capture->pending($limit);
      if ($events === []) {
        return $this->result(0, 0, 0, 0, 0, 0, 0, 0, $captureStatus->pendingEvents, $started, $memoryStart, SyncResultStatus::NoWork, $topology->primary->label(), $topology->standby->label(), $topology->profile->value);
      }
      $batch = $this->reducer->reduce($events, $inventory, $topology->profile);
      $applier = $this->applier($topology->standby->engine);
      $counts = ['table_dirty' => 0, 'upserts' => 0, 'deletes' => 0, 'policy_noops' => 0, 'table_reconciliations' => 0];
      $transaction = $topology->standby->connection->startTransaction();
      try {
        foreach ($batch->identities as $identity) {
          $table = $this->table($inventory, $identity);
          $schemaOnly = $topology->profile === ReplicationProfile::Clean
            && $this->cleanPolicy->tableDecision($table) === ReplicationDecision::SchemaOnly;
          if ($identity->key === NULL || $schemaOnly) {
            if (!$schemaOnly) {
              $counts['table_dirty']++;
              $this->assertTableReconciliationSafe($inventory, $table);
            }
            else {
              $counts['policy_noops']++;
            }
            $rows = $this->tableReconciler->reconcile(
              $topology->primary->connection,
              $topology->standby->connection,
              $table,
              $applier,
              $schemaOnly,
            );
            $counts['table_reconciliations']++;
            if ($schemaOnly && $rows !== 0) {
              throw new DbtngException(sprintf('Clean profile failed to empty schema-only table "%s".', $table->name));
            }
            continue;
          }
          $row = $this->rowReader->read($topology->primary->connection, $table, $identity->key);
          if ($row === NULL) {
            $applier->delete($topology->standby->connection, $table, $identity->key);
            $counts['deletes']++;
          }
          else {
            $applier->upsert($topology->standby->connection, $table, $row);
            $counts['upserts']++;
          }
        }
        unset($transaction);
      }
      catch (\Throwable $exception) {
        if (is_object($transaction)) {
          $transaction->rollBack();
        }
        throw $exception;
      }

      // A DDL change has no row event. Recheck schema after standby durability
      // and before exact acknowledgement, leaving events pending on mismatch.
      $finalInventory = $this->introspector->inspect($topology->primary->connection);
      if (!hash_equals($manifest['schema_fingerprint'], $this->fingerprints->calculate($finalInventory))) {
        throw new SyncRebuildRequiredException('Sync committed standby effects but schema changed; exact events remain pending and rebuild is required.');
      }
      $batchUuid = bin2hex(random_bytes(16));
      $manifestChanges = [
        'last_sync_batch_uuid' => $batchUuid,
        'last_sync_at' => gmdate(DATE_ATOM),
        'last_sync_event_count' => count($batch->eventIds),
        'last_sync_profile' => $topology->profile->value,
        'standby_data_modified_by_sync' => TRUE,
      ];
      if ($topology->standby->engine === DatabaseEngine::Sqlite) {
        // The immutable rebuild artifact hash describes the generation at
        // publication. Once sync mutates it, that checksum is no longer valid.
        $manifestChanges['artifact_sha256'] = NULL;
      }
      $this->manifests->updateVersionMetadata($topology->standby->identity, (string) $manifest['generation_id'], $manifestChanges);
      $this->failures->hit('after_standby_commit_before_ack', ['event_count' => count($batch->eventIds)]);
      $this->capture->acknowledge($batch->eventIds);
      $pendingAfter = $this->capture->status()->pendingEvents;
      $result = $pendingAfter === 0 ? SyncResultStatus::CaughtUp : SyncResultStatus::MorePending;
      return $this->result(
        count($batch->events), count($batch->identities), $counts['table_dirty'], $counts['upserts'],
        $counts['deletes'], $counts['policy_noops'], $counts['table_reconciliations'], count($batch->eventIds),
        $pendingAfter, $started, $memoryStart, $result, $topology->primary->label(), $topology->standby->label(), $topology->profile->value,
      );
    }
    catch (DatabaseExceptionWrapper | \PDOException $exception) {
      throw new SyncTransientException('Sync encountered a transient database failure; unacknowledged events remain pending.', 0, $exception);
    }
    catch (DbtngException $exception) {
      throw $exception;
    }
    catch (\Throwable $exception) {
      throw new DbtngException('Sync batch failed; unacknowledged changes remain replayable.', 0, $exception);
    }
    finally {
      $lock->release();
    }
  }

  public function applyPending(?int $limit = NULL): SyncStatus {
    $this->syncOnce($limit ?? 500);
    return $this->status();
  }

  public function status(): SyncStatus {
    try {
      $status = $this->capture->status();
      return new SyncStatus($status->pendingEvents, $status->healthy, $status->oldestEventId, $status->newestEventId, $status->oldestPendingAgeSeconds);
    }
    catch (\Throwable) {
      return new SyncStatus(0, FALSE);
    }
  }

  /**
   * Resolves the manifest for the exact currently published standby generation.
   *
   * @return array<string, mixed>
   */
  private function validatedManifest(Connection $standby, DatabaseEngine $engine, string $identity, ?string $path): array {
    $generation = NULL;
    if ($engine === DatabaseEngine::Sqlite) {
      if ($path === NULL || !is_link($path)) {
        throw new SyncRebuildRequiredException('Sync blocked: SQLite standby is not a published generation; rebuild required.');
      }
      $real = realpath($path);
      $generation = $real === FALSE ? NULL : basename(dirname($real));
    }
    else {
      try {
        $generation = $standby->query('SELECT generation_id FROM ' . SqlIdentifier::quote($standby, 'dbtng_migrator_snapshot_state') . ' LIMIT 1')?->fetchField();
      }
      catch (\Throwable) {
        $generation = NULL;
      }
    }
    if (!is_string($generation) || preg_match('/^[a-f0-9]{32}$/D', $generation) !== 1) {
      throw new SyncRebuildRequiredException('Sync blocked: standby has no valid published generation; rebuild required.');
    }
    $manifest = $this->manifests->readCurrent($identity, $generation);
    if ($manifest === NULL || !is_string($manifest['schema_fingerprint'] ?? NULL) || !is_string($manifest['profile'] ?? NULL)) {
      throw new SyncRebuildRequiredException('Sync blocked: standby manifest is missing or invalid; rebuild required.');
    }
    return $manifest;
  }

  /**
   * Verifies that the active topology/profile still matches its baseline.
   *
   * @param array<string, mixed> $manifest
   */
  private function assertBaseline(DatabaseEngine $primary, DatabaseEngine $standby, ReplicationProfile $profile, DatabaseInventory $inventory, array $manifest): void {
    if (($manifest['profile'] ?? NULL) !== $profile->value
      || ($manifest['primary_engine'] ?? NULL) !== $primary->value
      || ($manifest['standby_engine'] ?? NULL) !== $standby->value) {
      throw new SyncRebuildRequiredException('Sync blocked: standby profile/topology differs from its baseline; rebuild required.');
    }
    if (!hash_equals((string) $manifest['schema_fingerprint'], $this->fingerprints->calculate($inventory))) {
      throw new SyncRebuildRequiredException('Sync blocked: primary schema differs from its baseline; rebuild required.');
    }
    if ($profile === ReplicationProfile::Full
      && (($manifest['activatable'] ?? FALSE) !== TRUE || ($manifest['full_fidelity'] ?? FALSE) !== TRUE)) {
      throw new SyncRebuildRequiredException('Sync blocked: standby baseline is not full-fidelity and activatable; rebuild required.');
    }
  }

  private function assertStandbySchema(DatabaseInventory $primary, DatabaseInventory $standby): void {
    $sourceTables = [];
    foreach ($primary->tables as $table) {
      $sourceTables[$table->name] = $table;
    }
    $destinationTables = [];
    foreach ($standby->tables as $table) {
      $destinationTables[$table->name] = $table;
    }
    $sourceNames = array_keys($sourceTables);
    $destinationNames = array_keys($destinationTables);
    sort($sourceNames, SORT_STRING);
    sort($destinationNames, SORT_STRING);
    if ($sourceNames !== $destinationNames) {
      throw new SyncRebuildRequiredException('Sync blocked: standby table set differs from its baseline; rebuild required.');
    }
    foreach ($sourceNames as $name) {
      $source = $sourceTables[$name];
      $destination = $destinationTables[$name];
      $sourceColumns = array_map(static fn ($column): array => [
        $column->name,
        $column->portableType,
        $column->nullable && !in_array($column->name, $source->primaryKey, TRUE),
      ], $source->columns);
      $destinationColumns = array_map(static fn ($column): array => [
        $column->name,
        $column->portableType,
        $column->nullable && !in_array($column->name, $destination->primaryKey, TRUE),
      ], $destination->columns);
      if ($sourceColumns !== $destinationColumns || $source->primaryKey !== $destination->primaryKey
        || $this->indexSignature($source) !== $this->indexSignature($destination)) {
        throw new SyncRebuildRequiredException(sprintf('Sync blocked: standby schema differs for "%s"; rebuild required.', $name));
      }
    }
  }

  /**
   * Returns the normalized non-primary index signatures.
   *
   * @return list<array{bool, list<?string>}>
   */
  private function indexSignature(TableDefinition $table): array {
    $indexes = [];
    foreach ($table->indexDefinitions as $index) {
      if ($index->primary) {
        continue;
      }
      $indexes[] = [$index->unique, array_map(static fn ($column): ?string => $column->name, $index->columns)];
    }
    sort($indexes);
    return $indexes;
  }

  private function table(DatabaseInventory $inventory, DirtyIdentity $identity): TableDefinition {
    foreach ($inventory->tables as $table) {
      if ($table->name === $identity->table) {
        return $table;
      }
    }
    throw new SyncRebuildRequiredException('Sync blocked: dirty event table is absent from physical inventory; rebuild required.');
  }

  private function assertTableReconciliationSafe(DatabaseInventory $inventory, TableDefinition $table): void {
    foreach ($inventory->tables as $candidate) {
      if ($candidate->name === $table->name && $candidate->foreignKeys !== []) {
        throw new SyncRebuildRequiredException(sprintf('Table-dirty reconciliation of "%s" is blocked by foreign keys; rebuild required.', $table->name));
      }
      foreach ($candidate->foreignKeys as $foreignKey) {
        if ($foreignKey->referencedTable === $table->name) {
          throw new SyncRebuildRequiredException(sprintf('Table-dirty reconciliation of "%s" is blocked by foreign keys; rebuild required.', $table->name));
        }
      }
    }
  }

  private function applier(DatabaseEngine $engine): StandbyChangeApplierInterface {
    return match ($engine) {
      DatabaseEngine::MysqlFamily => $this->mysqlApplier,
      DatabaseEngine::Sqlite => $this->sqliteApplier,
    };
  }

  private function result(
    int $events,
    int $identities,
    int $tableDirty,
    int $upserts,
    int $deletes,
    int $policyNoops,
    int $tableRecon,
    int $acknowledged,
    int $pending,
    int $started,
    int $memoryStart,
    SyncResultStatus $status,
    string $primary,
    string $standby,
    string $profile,
  ): SyncBatchResult {
    return new SyncBatchResult(
      $events, $identities, $tableDirty, $upserts, $deletes, $policyNoops, $tableRecon,
      $acknowledged, $pending, (int) ((hrtime(TRUE) - $started) / 1_000_000),
      max(0, memory_get_peak_usage(TRUE) - $memoryStart), $status, $primary, $standby, $profile,
    );
  }

}
