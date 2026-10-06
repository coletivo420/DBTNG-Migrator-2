<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Connection;

use Drupal\Core\Site\Settings;
use Drupal\dbtng_migrator\Contract\RuntimeTopologySettingsInterface;

/**
 * Reads the non-secret runtime role declaration from settings.php. */
final class DrupalRuntimeTopologySettings implements RuntimeTopologySettingsInterface {

  public function getAll(): array {
    $settings = Settings::get('dbtng_migrator', []);
    return is_array($settings) ? $settings : [];
  }

}
