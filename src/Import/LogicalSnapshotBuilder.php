<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Import;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Database\StatementInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Contract\FailureInjectorInterface;
use Drupal\dbtng_migrator\Exception\PortabilityException;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\LogicalBuildResult;
use Drupal\dbtng_migrator\Model\ReplicationDecision;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\TableNameMap;
use Drupal\dbtng_migrator\Policy\CleanReplicationPolicy;
use Drupal\dbtng_migrator\Schema\MysqlIntegrityChecker;
use Drupal\dbtng_migrator\Schema\MysqlSchemaBuilder;
use Drupal\dbtng_migrator\Schema\PortabilityAnalyzer;
use Drupal\dbtng_migrator\Schema\SchemaFingerprint;
use Drupal\dbtng_migrator\Schema\SchemaIntrospectionManager;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;
use Drupal\dbtng_migrator\Schema\SqliteSchemaBuilder;

/**
 * Shared bounded schema/data build and validation used by import and rebuild. */
final class LogicalSnapshotBuilder {

  public function __construct(
    private readonly SchemaIntrospectionManager $introspector,
    private readonly PortabilityAnalyzer $portabilityAnalyzer,
    private readonly ImportPortabilityGate $portabilityGate,
    private readonly MysqlSchemaBuilder $mysqlBuilder,
    private readonly SqliteSchemaBuilder $sqliteBuilder,
    private readonly RowTransfer $transfer,
    private readonly CleanReplicationPolicy $cleanPolicy,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MysqlIntegrityChecker $mysqlIntegrityChecker,
    private readonly SchemaFingerprint $fingerprints,
    private readonly FailureInjectorInterface $failures,
  ) {}

  /**
   * Builds and validates a bounded logical snapshot in the supplied database.
   *
   * @param list<string>|null $candidateTables
   *   Physical candidate table names to introspect after construction.
   * @param string|null $candidateId
   *   Candidate identifier that enables injected rebuild failpoints.
   */
  public function build(
    Connection $source,
    Connection $destination,
    DatabaseInventory $inventory,
    ReplicationProfile $profile,
    ?TableNameMap $tableNames = NULL,
    ?string $constraintNamespace = NULL,
    ?array $candidateTables = NULL,
    ?string $candidateId = NULL,
  ): LogicalBuildResult {
    $destinationEngine = $this->engine($destination);
    $report = $this->portabilityAnalyzer->analyze($inventory);
    $this->portabilityGate->assertImportable($inventory, $destinationEngine, $report);
    if ($inventory->objects !== []) {
      $first = $inventory->objects[0];
      throw new PortabilityException(sprintf('Logical snapshot is blocked by unsupported %s object "%s".', $first->type, $first->name));
    }
    if ($inventory->databaseEngine === DatabaseEngine::MysqlFamily) {
      foreach ($inventory->tables as $table) {
        if (strtolower((string) $table->storageEngine) !== 'innodb') {
          throw new DbtngException(sprintf('Consistent snapshot is blocked by non-InnoDB source table "%s".', $table->name));
        }
      }
    }
    if ($destinationEngine === DatabaseEngine::Sqlite) {
      $destination->query('PRAGMA foreign_keys = OFF');
      $this->sqliteBuilder->createTables($destination, $inventory, $tableNames);
    }
    else {
      $this->mysqlBuilder->createTables($destination, $inventory, $tableNames);
    }
    if ($candidateId !== NULL) {
      $this->failures->hit('after_schema_build', ['candidate' => $candidateId]);
    }

    $expectedRows = [];
    foreach ($inventory->tables as $table) {
      $expectedRows[$table->name] = $profile === ReplicationProfile::Clean
        && $this->cleanPolicy->tableDecision($table) === ReplicationDecision::SchemaOnly
        ? 0
        : $this->countRows($source, $table->name);
    }
    $settings = $this->configFactory->get('dbtng_migrator.settings');
    $batchRows = max(1, (int) ($settings->get('snapshot.batch_rows') ?? 500));
    $batchBytes = max(1024, (int) ($settings->get('snapshot.batch_bytes') ?? 4_194_304));
    $transfer = $this->transfer->transfer($source, $destination, $inventory, $profile, $batchRows, $batchBytes, $tableNames);

    $indexWarnings = 0;
    if ($destinationEngine === DatabaseEngine::MysqlFamily) {
      $indexWarnings = $this->mysqlBuilder->createIndexesAndConstraints($destination, $inventory, $tableNames, $constraintNamespace);
    }
    else {
      $this->sqliteBuilder->createIndexes($destination, $inventory, $tableNames);
      $destination->query('PRAGMA foreign_keys = ON');
    }

    if ($candidateId !== NULL) {
      $this->failures->hit('before_validation', ['candidate' => $candidateId]);
    }
    $candidateInventory = $this->introspector->inspect($destination, $candidateTables);
    $this->validateSchema($candidateInventory, $inventory, $tableNames);
    $this->validateRows($destination, $inventory, $expectedRows, $tableNames);
    $this->validateIntegrity($destination, $candidateInventory);
    if ($candidateId !== NULL) {
      $this->failures->hit('after_validation', ['candidate' => $candidateId]);
    }

    return new LogicalBuildResult(
      $inventory,
      $candidateInventory,
      $expectedRows,
      count($inventory->tables),
      array_sum($expectedRows),
      $transfer['bytes'],
      $transfer['batches'],
      memory_get_peak_usage(TRUE),
      $report->countBySeverity('warning') + $indexWarnings,
      $this->fingerprints->calculate($inventory),
    );
  }

  private function engine(Connection $connection): DatabaseEngine {
    return strtolower($connection->driver()) === 'mysql' ? DatabaseEngine::MysqlFamily : DatabaseEngine::Sqlite;
  }

  private function countRows(Connection $connection, string $table): int {
    $statement = $connection->query('SELECT COUNT(*) FROM ' . SqlIdentifier::quote($connection, $table));
    if (!$statement instanceof StatementInterface) {
      throw new DbtngException('Unable to count a table in the consistent source snapshot.');
    }
    return (int) $statement->fetchField();
  }

  /**
   * Validates row counts in the candidate against the stable source view.
   *
   * @param array<string, int> $expected
   */
  private function validateRows(Connection $destination, DatabaseInventory $source, array $expected, ?TableNameMap $tableNames): void {
    foreach ($source->tables as $table) {
      $name = $tableNames?->destination($table->name) ?? $table->name;
      if ($this->countRows($destination, $name) !== $expected[$table->name]) {
        throw new DbtngException(sprintf('Row-count validation failed for candidate table "%s".', $table->name));
      }
    }
  }

  private function validateSchema(DatabaseInventory $candidate, DatabaseInventory $source, ?TableNameMap $tableNames): void {
    $expectedNames = [];
    foreach ($source->tables as $table) {
      $expectedNames[] = $tableNames?->destination($table->name) ?? $table->name;
    }
    $actualNames = array_map(static fn ($table): string => $table->name, $candidate->tables);
    sort($expectedNames, SORT_STRING);
    sort($actualNames, SORT_STRING);
    if ($expectedNames !== $actualNames) {
      throw new DbtngException('Candidate schema validation failed: table names differ from the source inventory.');
    }
    $actualByName = [];
    foreach ($candidate->tables as $table) {
      $actualByName[$table->name] = $table;
    }
    foreach ($source->tables as $table) {
      $candidateName = $tableNames?->destination($table->name) ?? $table->name;
      $actual = $actualByName[$candidateName] ?? NULL;
      if ($actual === NULL) {
        throw new DbtngException(sprintf('Candidate schema validation failed for table "%s".', $table->name));
      }
      $sourceColumns = array_map(static fn ($column): string => $column->name, array_filter($table->columns, static fn ($column): bool => !$column->hidden));
      $candidateColumns = array_map(static fn ($column): string => $column->name, array_filter($actual->columns, static fn ($column): bool => !$column->hidden));
      if ($sourceColumns !== $candidateColumns || $table->primaryKey !== $actual->primaryKey) {
        throw new DbtngException(sprintf('Candidate schema validation failed for table "%s".', $table->name));
      }
    }
  }

  private function validateIntegrity(Connection $destination, DatabaseInventory $candidateInventory): void {
    if ($this->engine($destination) === DatabaseEngine::MysqlFamily) {
      $this->mysqlIntegrityChecker->validate($destination, $candidateInventory);
      return;
    }
    $statement = $destination->query('PRAGMA integrity_check');
    if (!$statement instanceof StatementInterface || $statement->fetchField() !== 'ok') {
      throw new DbtngException('SQLite candidate failed PRAGMA integrity_check.');
    }
    $statement = $destination->query('PRAGMA foreign_key_check');
    if ($statement instanceof StatementInterface && $statement->fetch(FetchAs::Associative) !== FALSE) {
      throw new DbtngException('SQLite candidate failed PRAGMA foreign_key_check.');
    }
  }

}
