<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Maps logical source table names to isolated destination candidate names. */
final readonly class TableNameMap {

  /**
   * Creates a one-to-one source-to-candidate table name mapping.
   *
   * @param array<string, string> $names
   *   Source name to destination name mapping.
   */
  public function __construct(private array $names = []) {
    foreach ($names as $source => $destination) {
      if ($source === '' || $destination === '') {
        throw new \InvalidArgumentException('Mapped physical table names cannot be empty.');
      }
    }
    if (count(array_unique(array_values($names))) !== count($names)) {
      throw new \InvalidArgumentException('Candidate table mappings must be one-to-one.');
    }
  }

  public function destination(string $sourceName): string {
    return $this->names[$sourceName] ?? $sourceName;
  }

  public function source(string $destinationName): string {
    $source = array_search($destinationName, $this->names, TRUE);
    return $source === FALSE ? $destinationName : (string) $source;
  }

  /**
   * Returns the complete source-to-destination mapping.
   *
   * @return array<string, string>
   */
  public function all(): array {
    return $this->names;
  }

}
