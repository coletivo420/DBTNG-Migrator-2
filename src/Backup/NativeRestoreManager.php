<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Backup;

use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\NativeRestoreManagerInterface;
use Drupal\dbtng_migrator\Destination\DestinationPreparationManager;
use Drupal\dbtng_migrator\Destination\DestinationStateInspectionManager;
use Drupal\dbtng_migrator\Schema\SchemaIntrospectionManager;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\DestinationState;
use Drupal\dbtng_migrator\Model\NativeRestoreRequest;
use Drupal\dbtng_migrator\Model\NativeRestoreResult;

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
  ) {}

  public function restore(DatabaseTopology $topology, NativeRestoreRequest $request): NativeRestoreResult {
    $resolved = $this->resolver->resolve();
    if (!$this->sameTopology($topology, $resolved->topology)
      || $resolved->primary->identity === $resolved->standby->identity
      || $request->format->databaseEngine() !== $resolved->standby->engine) {
      throw new DbtngException('Native restore refused: artifact engine or destination does not match the configured standby.');
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
    $tables = array_map(static fn ($table): string => strtolower($table->name), $inventory->tables);
    foreach (['config', 'key_value', 'users_field_data'] as $requiredTail) {
      if (!array_filter($tables, static fn (string $table): bool => $table === $requiredTail || str_ends_with($table, '_' . $requiredTail))) {
        throw new DbtngException(sprintf('Native restore validation did not find the Drupal table "%s".', $requiredTail));
      }
    }
    return new NativeRestoreResult($after->standby->engine, $request->format, $preparation);
  }

  private function sameTopology(DatabaseTopology $expected, DatabaseTopology $actual): bool {
    return $expected->primaryEngine === $actual->primaryEngine
      && $expected->standbyEngine === $actual->standbyEngine
      && $expected->primaryConnectionKey === $actual->primaryConnectionKey
      && $expected->standbyConnectionKey === $actual->standbyConnectionKey;
  }

}
