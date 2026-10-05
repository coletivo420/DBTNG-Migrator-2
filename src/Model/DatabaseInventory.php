<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Immutable source database inventory.
 */
final readonly class DatabaseInventory {

  /**
   * @param list<\Drupal\dbtng_migrator\Model\TableDefinition> $tables
   */
  public function __construct(
    public DatabaseEngine $databaseEngine,
    public array $tables,
  ) {}

  public function tableCount(): int {
    return count($this->tables);
  }

}
