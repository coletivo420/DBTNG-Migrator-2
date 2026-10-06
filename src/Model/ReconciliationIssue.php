<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * One actionable difference or blocker found during reconciliation. */
final readonly class ReconciliationIssue {

  public function __construct(
    public string $code,
    public string $message,
    public ?string $table = NULL,
    public ?string $object = NULL,
  ) {}

}
