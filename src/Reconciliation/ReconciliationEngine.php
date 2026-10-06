<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Reconciliation;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\ReconciliationEngineInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Manifest\StandbyManifestStore;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\ReconciliationIssue;
use Drupal\dbtng_migrator\Model\ReconciliationReport;
use Drupal\dbtng_migrator\Model\ReconciliationStatus;
use Drupal\dbtng_migrator\Model\ReplicationDecision;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Model\TableReconciliationResult;
use Drupal\dbtng_migrator\Operation\OperationLock;
use Drupal\dbtng_migrator\Policy\CleanReplicationPolicy;
use Drupal\dbtng_migrator\Schema\MysqlIntegrityChecker;
use Drupal\dbtng_migrator\Schema\SchemaFingerprint;
use Drupal\dbtng_migrator\Schema\SchemaIntrospectionManager;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;

/**
 * Read-only comparison of the primary, standby and last successful manifest. */
final class ReconciliationEngine implements ReconciliationEngineInterface {

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly SchemaIntrospectionManager $introspector,
    private readonly StandbyManifestStore $manifests,
    private readonly SchemaFingerprint $fingerprints,
    private readonly CleanReplicationPolicy $cleanPolicy,
    private readonly MysqlIntegrityChecker $mysqlIntegrity,
    private readonly TableContentFingerprint $contentFingerprint,
    private readonly OperationLock $locks,
  ) {}

  public function reconcile(): ReconciliationReport {
    $lock = $this->locks->acquire('reconcile');
    try {
      return $this->inspectCurrent();
    }
    finally {
      $lock->release();
    }
  }

  private function inspectCurrent(): ReconciliationReport {
    $topology = $this->resolver->resolve();
    $primary = $this->introspector->inspect($topology->primary->connection);
    $standby = $this->introspector->inspect($topology->standby->connection);
    $generation = $this->publishedGeneration($topology->standby->connection, $topology->standby->engine, $topology->standby->databasePath);
    $manifest = $this->manifests->readCurrent($topology->standby->identity, $generation);
    $issues = [];
    if ($manifest === NULL || !isset($manifest['profile'])) {
      $profile = ReplicationProfile::Full;
      $issues[] = new ReconciliationIssue('manifest_missing', 'No successful snapshot manifest describes the published standby.');
      $status = ReconciliationStatus::RebuildRequired;
    }
    else {
      $profile = ReplicationProfile::tryFrom((string) $manifest['profile']) ?? ReplicationProfile::Full;
      $status = ReconciliationStatus::Match;
    }
    if (!$topology->topology->supportsProfile($profile)) {
      $issues[] = new ReconciliationIssue('profile_unsupported', 'The recorded profile is not activatable for the current role direction.');
      $status = ReconciliationStatus::Blocked;
    }

    $primaryByName = $this->indexTables($primary);
    $standbyByName = $this->indexTables($standby);
    $names = array_values(array_unique(array_merge(array_keys($primaryByName), array_keys($standbyByName))));
    sort($names, SORT_STRING);
    $rows = [];
    foreach ($names as $name) {
      $source = $primaryByName[$name] ?? NULL;
      $destination = $standbyByName[$name] ?? NULL;
      if (!$source instanceof TableDefinition || !$destination instanceof TableDefinition) {
        $issues[] = new ReconciliationIssue('table_set', $source === NULL ? 'Unexpected table exists on standby.' : 'Expected table is missing from standby.', $name);
        $rows[] = new TableReconciliationResult($name, FALSE, NULL, NULL, ReplicationDecision::Copy);
        $status = $status === ReconciliationStatus::Blocked ? $status : ReconciliationStatus::Drift;
        continue;
      }
      $schemaMatches = $this->tableSchemaMatches($source, $destination);
      if (!$schemaMatches) {
        $issues[] = new ReconciliationIssue('schema', 'Columns, primary key, or indexes differ.', $name);
        $status = $status === ReconciliationStatus::Blocked ? $status : ReconciliationStatus::Drift;
      }
      $decision = $profile === ReplicationProfile::Clean ? $this->cleanPolicy->tableDecision($source) : ReplicationDecision::Copy;
      $expected = $this->countRows($topology->primary->connection, $name);
      $actual = $this->countRows($topology->standby->connection, $name);
      if ($decision === ReplicationDecision::SchemaOnly) {
        $expected = 0;
      }
      if ($actual !== $expected) {
        $issues[] = new ReconciliationIssue('row_count', sprintf('Expected %d rows but found %d.', $expected, $actual), $name);
        $status = $status === ReconciliationStatus::Blocked ? $status : ReconciliationStatus::Drift;
      }
      $contentMatches = $decision === ReplicationDecision::SchemaOnly
        ? $actual === 0
        : $this->contentFingerprint->calculate($topology->primary->connection, $source)['digest']
          === $this->contentFingerprint->calculate($topology->standby->connection, $destination)['digest'];
      if (!$contentMatches) {
        $issues[] = new ReconciliationIssue('content', 'Row content differs despite any matching row counts.', $name);
        $status = $status === ReconciliationStatus::Blocked ? $status : ReconciliationStatus::Drift;
      }
      $rows[] = new TableReconciliationResult($name, $schemaMatches, $expected, $actual, $decision, $contentMatches);
    }
    if ($primary->objects !== [] || $standby->objects !== []) {
      $issues[] = new ReconciliationIssue('objects', 'Non-table database objects exist and are outside the current replication profile.');
      $status = ReconciliationStatus::Blocked;
    }

    $sourceFingerprint = $this->fingerprints->calculate($primary);
    $recordedFingerprint = $manifest['schema_fingerprint'] ?? NULL;
    if (is_string($recordedFingerprint) && !hash_equals($recordedFingerprint, $sourceFingerprint)) {
      $issues[] = new ReconciliationIssue('source_schema_changed', 'Primary schema no longer matches the last rebuilt snapshot.');
      $status = $status === ReconciliationStatus::Blocked ? $status : ReconciliationStatus::RebuildRequired;
    }
    $integrityPass = $this->integrity($topology->standby->connection, $standby);
    if (!$integrityPass) {
      $issues[] = new ReconciliationIssue('integrity', 'Standby engine integrity checks failed.');
      $status = ReconciliationStatus::Blocked;
    }
    $manifestPass = $manifest !== NULL && isset($manifest['profile']);
    if ($topology->standby->engine === DatabaseEngine::Sqlite
      && is_string($manifest['artifact_sha256'] ?? NULL)
      && $topology->standby->databasePath !== NULL) {
      $actualHash = hash_file('sha256', $topology->standby->databasePath);
      if (!is_string($actualHash) || !hash_equals($manifest['artifact_sha256'], $actualHash)) {
        $manifestPass = FALSE;
        $issues[] = new ReconciliationIssue('manifest_checksum', 'Published SQLite artifact checksum differs from its manifest.');
        $status = ReconciliationStatus::Drift;
      }
    }
    if ($status === ReconciliationStatus::Match && !$manifestPass) {
      $status = ReconciliationStatus::RebuildRequired;
    }
    return new ReconciliationReport(
      $status,
      $profile,
      $topology->primary->label(),
      $topology->standby->label(),
      $rows,
      $issues,
      $integrityPass,
      $manifestPass,
      $sourceFingerprint,
    );
  }

  /**
   * Indexes the inventory by physical table name.
   *
   * @return array<string, TableDefinition>
   */
  private function indexTables(DatabaseInventory $inventory): array {
    $result = [];
    foreach ($inventory->tables as $table) {
      $result[$table->name] = $table;
    }
    return $result;
  }

  private function tableSchemaMatches(TableDefinition $source, TableDefinition $destination): bool {
    $sourceColumns = array_map(static fn ($column): array => [$column->name, $column->portableType, $column->nullable], $source->columns);
    $destColumns = array_map(static fn ($column): array => [$column->name, $column->portableType, $column->nullable], $destination->columns);
    if ($sourceColumns !== $destColumns || $source->primaryKey !== $destination->primaryKey) {
      return FALSE;
    }
    $signature = static function (TableDefinition $table): array {
      $indexes = [];
      foreach ($table->indexDefinitions as $index) {
        if ($index->primary) {
          continue;
        }
        $indexes[] = [$index->unique, array_map(static fn ($column): ?string => $column->name, $index->columns)];
      }
      sort($indexes);
      return $indexes;
    };
    return $signature($source) === $signature($destination);
  }

  private function countRows(Connection $connection, string $table): int {
    $statement = $connection->query('SELECT COUNT(*) FROM ' . SqlIdentifier::quote($connection, $table));
    if (!$statement instanceof StatementInterface) {
      throw new DbtngException('Unable to count rows during reconciliation.');
    }
    return (int) $statement->fetchField();
  }

  private function integrity(Connection $connection, DatabaseInventory $inventory): bool {
    if ($inventory->databaseEngine === DatabaseEngine::MysqlFamily) {
      try {
        $this->mysqlIntegrity->validate($connection, $inventory);
        return TRUE;
      }
      catch (\Throwable) {
        return FALSE;
      }
    }
    $check = $connection->query('PRAGMA integrity_check');
    if (!$check instanceof StatementInterface || $check->fetchField() !== 'ok') {
      return FALSE;
    }
    $foreignKeys = $connection->query('PRAGMA foreign_key_check');
    return !$foreignKeys instanceof StatementInterface || $foreignKeys->fetch() === FALSE;
  }

  private function publishedGeneration(Connection $connection, DatabaseEngine $engine, ?string $path): ?string {
    if ($engine === DatabaseEngine::Sqlite && $path !== NULL && is_link($path)) {
      $realPath = realpath($path);
      $generation = $realPath === FALSE ? '' : basename(dirname($realPath));
      return preg_match('/^[a-f0-9]{32}$/D', $generation) === 1 ? $generation : NULL;
    }
    if ($engine === DatabaseEngine::MysqlFamily) {
      try {
        $statement = $connection->query('SELECT generation_id FROM dbtng_migrator_snapshot_state LIMIT 1');
        $generation = $statement?->fetchField();
        return is_string($generation) && preg_match('/^[a-f0-9]{32}$/D', $generation) === 1 ? $generation : NULL;
      }
      catch (\Throwable) {
        return NULL;
      }
    }
    return NULL;
  }

}
