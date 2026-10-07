<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * A bounded set of exact capture events reduced to unique identities. */
final readonly class DirtyBatch {

  /**
   * Creates one reduced event batch.
   *
   * @param list<ChangeRecord> $events
   * @param list<DirtyIdentity> $identities
   * @param list<int> $eventIds
   */
  public function __construct(
    public array $events,
    public array $identities,
    public array $eventIds,
  ) {}

}
