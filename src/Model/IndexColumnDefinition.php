<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * One ordered index component. A null name represents an expression.
 */
final readonly class IndexColumnDefinition {

  public function __construct(
    public ?string $name,
    public int $position,
    public ?int $prefixLength = NULL,
    public ?string $expression = NULL,
    public bool $descending = FALSE,
  ) {}

}
