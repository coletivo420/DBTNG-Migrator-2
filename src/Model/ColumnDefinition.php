<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Portable description of one physical source column.
 */
final readonly class ColumnDefinition {

  public function __construct(
    public string $name,
    public string $nativeType,
    public string $portableType,
    public bool $nullable,
    public mixed $default = NULL,
    public bool $unsigned = FALSE,
    public ?int $length = NULL,
    public ?int $precision = NULL,
    public ?int $scale = NULL,
    public bool $autoIncrement = FALSE,
    public bool $generated = FALSE,
    public ?string $generatedExpression = NULL,
    public ?string $charset = NULL,
    public ?string $collation = NULL,
    public bool $hidden = FALSE,
  ) {}

}
