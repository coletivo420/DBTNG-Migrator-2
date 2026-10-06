<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * A user-defined schema object outside a base table. */
final readonly class DatabaseObjectDefinition {

  public function __construct(
    public string $type,
    public string $name,
    public ?string $table = NULL,
  ) {}

}
