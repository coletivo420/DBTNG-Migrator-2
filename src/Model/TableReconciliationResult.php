<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Per-table schema and data comparison result. */
final readonly class TableReconciliationResult {

  public function __construct(
    public string $table,
    public bool $schemaMatches,
    public ?int $expectedRows,
    public ?int $actualRows,
    public ReplicationDecision $decision,
    public bool $contentMatches = TRUE,
  ) {}

}
