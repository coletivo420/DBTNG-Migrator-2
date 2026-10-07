<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * One durable primary-side dirty record.
 */
final readonly class ChangeRecord {

  /**
   * Constructs one durable dirty record.
   *
   * @param array<string, int|string|null>|null $key
   *   Current primary-key values for insert/update events.
   * @param array<string, int|string|null>|null $oldKey
   *   Previous primary-key values for update/delete events.
   */
  public function __construct(
    public int $id,
    public string $tableName,
    public ChangeOperation $operation,
    public ChangeIdentityKind $identityKind,
    public ?array $key,
    public ?array $oldKey,
    public string $capturedAt,
  ) {
    if ($id < 1 || $tableName === '') {
      throw new \InvalidArgumentException('Captured changes require a positive ID and table name.');
    }
  }

}
