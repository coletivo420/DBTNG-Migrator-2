<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

/**
 * Test seam for controlled failures at rebuild lifecycle boundaries. */
interface FailureInjectorInterface {

  /**
   * Raises an injected failure when the selected lifecycle point is reached.
   *
   * @param array<string, scalar|null> $context
   *   Non-sensitive candidate identifiers and metrics.
   */
  public function hit(string $point, array $context = []): void;

}
