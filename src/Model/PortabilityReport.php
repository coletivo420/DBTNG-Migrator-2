<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Aggregate result of a strict portability inspection.
 */
final readonly class PortabilityReport {

  /**
   * Creates a strict portability result from all schema findings.
   *
   * @param list<\Drupal\dbtng_migrator\Model\PortabilityIssue> $issues
   *   Findings.
   */
  public function __construct(public array $issues) {}

  public function isPortable(): bool {
    foreach ($this->issues as $issue) {
      if ($issue->severity === 'error') {
        return FALSE;
      }
    }
    return TRUE;
  }

  public function countBySeverity(string $severity): int {
    return count(array_filter($this->issues, static fn (PortabilityIssue $issue): bool => $issue->severity === $severity));
  }

}
