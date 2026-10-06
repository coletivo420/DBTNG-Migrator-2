<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Backup;

use Drupal\dbtng_migrator\Model\DatabaseRole;
use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\NativeBackupManagerInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\NativeBackupArtifact;
use Drupal\dbtng_migrator\Model\NativeBackupRequest;

/**
 * Resolves a requested role and delegates to its engine-specific adapter. */
final class NativeBackupManager implements NativeBackupManagerInterface {

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly BackupDirectoryManager $directoryManager,
    private readonly MysqlNativeBackupAdapter $mysqlAdapter,
    private readonly SqliteNativeBackupAdapter $sqliteAdapter,
  ) {}

  public function create(DatabaseTopology $topology, NativeBackupRequest $request): NativeBackupArtifact {
    $resolved = $this->resolver->resolve();
    if ($resolved->topology->primaryEngine !== $topology->primaryEngine || $resolved->topology->standbyEngine !== $topology->standbyEngine || $resolved->topology->primaryConnectionKey !== $topology->primaryConnectionKey || $resolved->topology->standbyConnectionKey !== $topology->standbyConnectionKey) {
      throw new DbtngException('The requested backup topology no longer matches runtime role configuration.');
    }
    $database = $request->role === DatabaseRole::Primary ? $resolved->primary : $resolved->standby;
    $directory = $this->directoryManager->prepare($request->outputDirectory);
    $adapter = $database->engine === DatabaseEngine::Sqlite ? $this->sqliteAdapter : $this->mysqlAdapter;
    return $adapter->create($database, $request->compression, $directory);
  }

}
