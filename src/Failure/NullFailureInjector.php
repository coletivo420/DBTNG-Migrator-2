<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Failure;

use Drupal\dbtng_migrator\Contract\FailureInjectorInterface;

/**
 * Production failure injector that never alters normal operation. */
final class NullFailureInjector implements FailureInjectorInterface {

  public function hit(string $point, array $context = []): void {
    // Deliberately empty in production.
  }

}
