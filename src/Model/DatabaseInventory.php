<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Immutable source database inventory.
 */
final readonly class DatabaseInventory {

  /**
   * Constructs a physical database inventory.
   *
   * @param \Drupal\dbtng_migrator\Model\DatabaseEngine $databaseEngine
   *   Database engine represented by this inventory.
   * @param list<\Drupal\dbtng_migrator\Model\TableDefinition> $tables
   *   Physical tables discovered in the database.
   */
  public function __construct(
    public DatabaseEngine $databaseEngine,
    public array $tables,
  ) {}

  public function tableCount(): int {
    return count($this->tables);
  }

}
