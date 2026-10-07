<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Sync;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\dbtng_migrator\Model\SyncWorkerConfiguration;

/**
 * Builds validated worker configuration from Drupal config.
 */
final class SyncWorkerConfigurationFactory {

  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  public function create(): SyncWorkerConfiguration {
    $values = $this->configFactory->get('dbtng_migrator.settings')->get('sync');
    $values = is_array($values) ? $values : [];
    return new SyncWorkerConfiguration(
      (int) ($values['batch_events'] ?? 500),
      (int) ($values['poll_seconds'] ?? 1),
      (int) ($values['health_check_seconds'] ?? 30),
      (int) ($values['backoff_initial_seconds'] ?? 1),
      (int) ($values['backoff_max_seconds'] ?? 30),
      (int) ($values['blocked_retry_seconds'] ?? 60),
      (int) ($values['heartbeat_stale_seconds'] ?? 120),
      (int) ($values['lag_warning_seconds'] ?? 60),
    );
  }

}
