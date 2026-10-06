<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Contract;

/**
 * Reads non-secret topology metadata established before Drupal bootstrap.
 */
interface RuntimeTopologySettingsInterface {

  /**
   * Returns role names and expected engines, never database credentials.
   *
   * @return array<string, mixed> */
  public function getAll(): array;

}
