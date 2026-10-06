<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Metrics and validation facts from building a logical snapshot candidate. */
final readonly class LogicalBuildResult {

  /**
   * Maps source table names to expected row counts for the selected profile.
   *
   * @param array<string, int> $expectedRows
   */
  public function __construct(
    public DatabaseInventory $sourceInventory,
    public DatabaseInventory $candidateInventory,
    public array $expectedRows,
    public int $tableCount,
    public int $rowCount,
    public int $bytesTransferred,
    public int $batchCount,
    public int $peakMemoryBytes,
    public int $portabilityWarnings,
    public string $schemaFingerprint,
  ) {}

}
