<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Physical foreign-key metadata.
 */
final readonly class ForeignKeyDefinition {

  /**
   * Creates one physical foreign-key definition.
   *
   * @param list<string> $columns
   *   Ordered local columns.
   * @param list<string> $referencedColumns
   *   Ordered referenced columns.
   */
  public function __construct(
    public string $name,
    public array $columns,
    public string $referencedTable,
    public array $referencedColumns,
    public ?string $onUpdate = NULL,
    public ?string $onDelete = NULL,
    public ?string $match = NULL,
  ) {}

}
