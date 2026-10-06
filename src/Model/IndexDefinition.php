<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Physical index metadata, including order and engine-specific features.
 */
final readonly class IndexDefinition {

  /**
   * Creates one physical index definition.
   *
   * @param list<\Drupal\dbtng_migrator\Model\IndexColumnDefinition> $columns
   *   Ordered index components.
   */
  public function __construct(
    public string $name,
    public bool $unique,
    public bool $primary,
    public array $columns,
    public ?string $type = NULL,
    public bool $partial = FALSE,
    public ?string $predicate = NULL,
    public bool $functional = FALSE,
  ) {}

}
