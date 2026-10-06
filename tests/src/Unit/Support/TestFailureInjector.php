<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Support;

use Drupal\dbtng_migrator\Contract\FailureInjectorInterface;

/**
 * Throws at one explicit rebuild lifecycle point during tests.
 */
final class TestFailureInjector implements FailureInjectorInterface {

  public function __construct(private readonly string $selectedPoint) {}

  public function hit(string $point, array $context = []): void {
    if ($point === $this->selectedPoint) {
      throw new \RuntimeException(sprintf('Injected failure at %s.', $point));
    }
  }

}
