<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Describes how change capture can identify rows for one table.
 */
final readonly class CaptureIdentityDefinition {

  /**
   * Constructs a row-identity plan.
   *
   * @param list<string> $columns
   *   Ordered physical primary-key columns when row-addressable.
   */
  public function __construct(
    public ChangeIdentityKind $kind,
    public array $columns = [],
  ) {
    if ($kind === ChangeIdentityKind::PrimaryKey && $columns === []) {
      throw new \InvalidArgumentException('Primary-key capture requires at least one key column.');
    }
    if ($kind === ChangeIdentityKind::Table && $columns !== []) {
      throw new \InvalidArgumentException('Table-dirty capture cannot carry row key columns.');
    }
  }

}
