<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\ChangeCapture;

use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\ChangeCaptureAdapterInterface;
use Drupal\dbtng_migrator\Contract\ChangeCaptureInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\ChangeCaptureStatus;
use Drupal\dbtng_migrator\Operation\OperationLock;
use Drupal\dbtng_migrator\Schema\SchemaIntrospectionManager;

/**
 * Resolves the selected primary and delegates capture to its engine adapter.
 */
final class ChangeCaptureManager implements ChangeCaptureInterface {

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly SchemaIntrospectionManager $schemas,
    private readonly MysqlFamilyChangeCaptureAdapter $mysql,
    private readonly SqliteChangeCaptureAdapter $sqlite,
    private readonly OperationLock $operationLock,
  ) {}

  public function install(): ChangeCaptureStatus {
    $lock = $this->operationLock->acquire('capture_install');
    try {
      [$adapter, $database] = $this->adapterAndDatabase();
      $inventory = $this->schemas->inspect($database->connection);
      return $adapter->install($database->connection, $inventory);
    }
    finally {
      $lock->release();
    }
  }

  public function uninstall(): void {
    $lock = $this->operationLock->acquire('capture_uninstall');
    try {
      [$adapter, $database] = $this->adapterAndDatabase();
      $adapter->uninstall($database->connection);
    }
    finally {
      $lock->release();
    }
  }

  public function isInstalled(): bool {
    return $this->status()->healthy;
  }

  public function status(): ChangeCaptureStatus {
    [$adapter, $database] = $this->adapterAndDatabase();
    $inventory = $this->schemas->inspect($database->connection);
    return $adapter->status($database->connection, $inventory);
  }

  public function pending(int $limit = 500): array {
    [$adapter, $database] = $this->adapterAndDatabase();
    return $adapter->pending($database->connection, $limit);
  }

  public function acknowledge(array $eventIds): void {
    [$adapter, $database] = $this->adapterAndDatabase();
    $adapter->acknowledge($database->connection, $eventIds);
  }

  /**
   * @return array{0: \Drupal\dbtng_migrator\Contract\ChangeCaptureAdapterInterface, 1: \Drupal\dbtng_migrator\Model\ResolvedDatabase}
   */
  private function adapterAndDatabase(): array {
    $resolved = $this->resolver->resolve();
    foreach ([$this->mysql, $this->sqlite] as $adapter) {
      if ($adapter->supports($resolved->primary->engine)) {
        return [$adapter, $resolved->primary];
      }
    }
    throw new DbtngException('No durable change-capture adapter supports the selected primary.');
  }

}
