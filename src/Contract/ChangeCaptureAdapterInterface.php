<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Model\ChangeCaptureStatus;
use Drupal\dbtng_migrator\Model\ChangeRecord;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;

/**
 * Engine-specific durable primary-side capture implementation.
 */
interface ChangeCaptureAdapterInterface {

  public function supports(DatabaseEngine $engine): bool;

  public function install(Connection $connection, DatabaseInventory $inventory): ChangeCaptureStatus;

  public function uninstall(Connection $connection): void;

  public function status(Connection $connection, DatabaseInventory $inventory): ChangeCaptureStatus;

  /**
   * Returns currently committed, unacknowledged events.
   *
   * Event IDs are identifiers, not a commit-order watermark.
   *
   * @return list<\Drupal\dbtng_migrator\Model\ChangeRecord>
   */
  public function pending(Connection $connection, int $limit = 500): array;

  /**
   * Acknowledges exact event IDs after their standby effect is durable.
   *
   * @param list<int> $eventIds
   *   Exact durable event identifiers to remove.
   */
  public function acknowledge(Connection $connection, array $eventIds): void;

}
