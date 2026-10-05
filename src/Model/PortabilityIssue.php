<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * One portability finding produced during preflight.
 */
final readonly class PortabilityIssue {

  public function __construct(
    public string $code,
    public string $message,
    public string $severity = 'error',
    public ?string $table = NULL,
    public ?string $column = NULL,
  ) {}

}
