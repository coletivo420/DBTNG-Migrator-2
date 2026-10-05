<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Portable physical table inventory entry.
 */
final readonly class TableDefinition {

  /**
   * Constructs a portable physical table definition.
   *
   * @param string $name
   *   Physical table name.
   * @param list<\Drupal\dbtng_migrator\Model\ColumnDefinition> $columns
   *   Column definitions.
   * @param list<string> $primaryKey
   *   Ordered primary-key columns.
   * @param array<string, list<string>> $uniqueKeys
   *   Unique indexes keyed by logical name.
   * @param array<string, list<string>> $indexes
   *   Non-unique indexes keyed by logical name.
   * @param string|null $storageEngine
   *   Source storage engine, when the database exposes one.
   * @param string|null $collation
   *   Source table collation, when available.
   */
  public function __construct(
    public string $name,
    public array $columns,
    public array $primaryKey = [],
    public array $uniqueKeys = [],
    public array $indexes = [],
    public ?string $storageEngine = NULL,
    public ?string $collation = NULL,
  ) {}

}
