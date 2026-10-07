<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * A current-state row key or a table-wide dirty marker. */
final readonly class DirtyIdentity {

  /**
   * Creates one row-level or table-level dirty identity.
   *
   * @param array<string, int|string|null>|null $key
   * @param list<int> $eventIds
   */
  public function __construct(
    public string $table,
    public ChangeIdentityKind $kind,
    public ?array $key,
    public array $eventIds,
  ) {}

}
