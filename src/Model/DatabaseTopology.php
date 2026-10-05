<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Immutable selection of primary and standby database roles.
 */
final readonly class DatabaseTopology {

  public function __construct(
    public DatabaseEngine $primaryEngine,
    public DatabaseEngine $standbyEngine,
    public string $primaryConnectionKey = 'default',
    public string $standbyConnectionKey = 'dbtng_standby',
  ) {
    if ($primaryEngine === $standbyEngine) {
      throw new \InvalidArgumentException(
        'Primary and standby must use different database engines in the initial DBTNG topology.',
      );
    }

    if ($primaryConnectionKey === '') {
      throw new \InvalidArgumentException('Primary connection key cannot be empty.');
    }

    if ($standbyConnectionKey === '') {
      throw new \InvalidArgumentException('Standby connection key cannot be empty.');
    }

    if ($primaryConnectionKey === $standbyConnectionKey) {
      throw new \InvalidArgumentException('Primary and standby connection keys must be different.');
    }
  }

  public static function default(): self {
    return new self(
      DatabaseEngine::MysqlFamily,
      DatabaseEngine::Sqlite,
    );
  }

  public function sqliteIsPrimary(): bool {
    return $this->primaryEngine === DatabaseEngine::Sqlite;
  }

  public function sqliteIsStandby(): bool {
    return $this->standbyEngine === DatabaseEngine::Sqlite;
  }

  public function supportsProfile(ReplicationProfile $profile): bool {
    return match ($profile) {
      ReplicationProfile::Full => TRUE,
      ReplicationProfile::Clean => $this->sqliteIsStandby(),
    };
  }

}
