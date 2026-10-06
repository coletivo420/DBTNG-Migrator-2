<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Destination state and the physical objects that caused that state.
 */
final readonly class DestinationStateInspection {

  /**
   * Creates the result of a destination inventory check.
   *
   * @param list<string> $reasons
   *   Human-readable object names/types, without database credentials.
   */
  public function __construct(
    public DestinationState $state,
    public array $reasons = [],
  ) {}

}
