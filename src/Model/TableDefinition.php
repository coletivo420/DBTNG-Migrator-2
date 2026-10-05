<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Portable physical table inventory entry.
 */
final readonly class TableDefinition {

  /**
   * @param list<\Drupal\dbtng_migrator\Model\ColumnDefinition> $columns
   * @param list<string> $primaryKey
   * @param array<string, list<string>> $uniqueKeys
   * @param array<string, list<string>> $indexes
   */
  public function __construct(
    public string $name,
    public array $columns,
    public array $primaryKey = [],
    public array $uniqueKeys = [],
    public array $indexes = [],
    public ?string $engine = NULL,
    public ?string $collation = NULL,
  ) {}

}
