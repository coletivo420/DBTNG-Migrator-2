<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

/**
 * Supplies UTC wall-clock time to operational worker code.
 */
interface ClockInterface {

  public function now(): \DateTimeImmutable;

}
