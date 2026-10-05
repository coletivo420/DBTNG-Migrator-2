<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

/**
 * Manages durable primary-side change capture infrastructure.
 */
interface ChangeCaptureInterface {

  public function install(): void;

  public function uninstall(): void;

  public function isInstalled(): bool;

}
