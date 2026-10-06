<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Backup;

use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\NativeRestoreManagerInterface;
use Drupal\dbtng_migrator\Destination\DestinationPreparationManager;
use Drupal\dbtng_migrator\Destination\DestinationStateInspectionManager;
use Drupal\dbtng_migrator\Schema\SchemaIntrospectionManager;
use Drupal\dbtng_migrator\Schema\MysqlIntegrityChecker;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\DestinationState;
use Drupal\dbtng_migrator\Model\NativeRestoreRequest;
use Drupal\dbtng_migrator\Model\NativeRestoreResult;
use Drupal\dbtng_migrator\Manifest\StandbyManifestStore;
use Drupal\dbtng_migrator\Operation\OperationLock;

/**
 * Validates same-engine native restores and applies the standby policy. */
final class NativeRestoreManager implements NativeRestoreManagerInterface {

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly DestinationPreparationManager $preparation,
    private readonly DestinationStateInspectionManager $inspector,
    private readonly SchemaIntrospectionManager $schemaIntrospector,
    private readonly MysqlNativeRestoreAdapter $mysql,
    private readonly SqliteNativeRestoreAdapter $sqlite,
    private readonly StandbyManifestStore $manifestStore,
    private readonly MysqlIntegrityChecker $mysqlIntegrityChecker,
    private readonly OperationLock $operationLock,
  ) {}

  public function restore(DatabaseTopology $topology, NativeRestoreRequest $request): NativeRestoreResult {
    $lock = $this->operationLock->acquire('restore');
    try {
      return $this->restoreLocked($topology, $request);
    }
    finally {
      $lock->release();
    }
  }

  private function restoreLocked(DatabaseTopology $topology, NativeRestoreRequest $request): NativeRestoreResult {
    $resolved = $this->resolver->resolve();
    if (!$this->sameTopology($topology, $resolved->topology)
      || $resolved->primary->identity === $resolved->standby->identity
      || $request->format->databaseEngine() !== $resolved->standby->engine) {
      throw new DbtngException('Native restore refused: artifact engine or destination does not match the configured standby.');
    }
    if ($resolved->standby->engine === DatabaseEngine::Sqlite) {
      $this->sqlite->validateArtifact($request);
    }
    else {
      $this->mysql->validateArtifact($request);
    }
    $atomicSqlite = $resolved->standby->engine === DatabaseEngine::Sqlite;
    $preparation = $this->preparation->prepare($topology, $request->nonEmptyPolicy, $atomicSqlite);
    if ($resolved->standby->engine === DatabaseEngine::Sqlite) {
      $this->sqlite->restore($resolved->standby, $request);
    }
    else {
      $this->mysql->restore($resolved->standby, $request);
    }
    $after = $this->resolver->resolve();
    if ($after->primary->identity === $after->standby->identity
      || $this->inspector->inspect($after->standby->connection)->state !== DestinationState::NonEmpty) {
      throw new DbtngException('Native restore did not produce a non-empty validated standby.');
    }
    $inventory = $this->schemaIntrospector->inspect($after->standby->connection);
    if ($after->standby->engine === DatabaseEngine::MysqlFamily) {
      $this->mysqlIntegrityChecker->validate($after->standby->connection, $inventory);
    }
    $tables = array_map(static fn ($table): string => strtolower($table->name), $inventory->tables);
    foreach (['config', 'key_value', 'users_field_data'] as $requiredTail) {
      if (!array_filter($tables, static fn (string $table): bool => $table === $requiredTail || str_ends_with($table, '_' . $requiredTail))) {
        throw new DbtngException(sprintf('Native restore validation did not find the Drupal table "%s".', $requiredTail));
      }
    }
    $manifestPath = $this->manifestStore->write($after->standby->identity, [
      'snapshot_id' => bin2hex(random_bytes(16)),
      'created_at' => gmdate(DATE_ATOM),
      'operation' => 'native_restore',
      'source_role' => 'backup_artifact',
      'source_engine' => $request->format->databaseEngine()->value,
      'destination_role' => 'standby',
      'destination_engine' => $after->standby->engine->value,
      'profile' => $after->profile->value,
      'portable' => TRUE,
      'activatable' => !$after->profile->isLossyProjection(),
      'table_count' => count($inventory->tables),
      'row_count' => NULL,
      'artifact_format' => $request->format->value,
      'artifact_bytes' => filesize($request->backupPath) ?: 0,
      'artifact_sha256' => hash_file('sha256', $request->backupPath) ?: NULL,
      'destination_policy' => $request->nonEmptyPolicy->value,
      'previous_destination_state' => $preparation->previousState->value,
      'validation' => [
        'schema' => 'pass',
        'integrity' => 'pass',
      ],
    ]);
    return new NativeRestoreResult($after->standby->engine, $request->format, $preparation, $manifestPath);
  }

  private function sameTopology(DatabaseTopology $expected, DatabaseTopology $actual): bool {
    return $expected->primaryEngine === $actual->primaryEngine
      && $expected->standbyEngine === $actual->standbyEngine
      && $expected->primaryConnectionKey === $actual->primaryConnectionKey
      && $expected->standbyConnectionKey === $actual->standbyConnectionKey;
  }

}
