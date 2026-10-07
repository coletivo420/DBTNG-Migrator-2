<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

/**
 * Suspends a worker without hard-coding sleep calls into its state machine.
 */
interface SleeperInterface {

  public function sleep(int $seconds): void;

}
