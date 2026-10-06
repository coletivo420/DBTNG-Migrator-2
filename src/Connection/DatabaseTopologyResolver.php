<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Connection;

use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Contract\DatabaseConnectionFactoryInterface;
use Drupal\dbtng_migrator\Contract\DatabaseTopologyResolverInterface;
use Drupal\dbtng_migrator\Contract\RuntimeTopologySettingsInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseProduct;
use Drupal\dbtng_migrator\Model\DatabaseRole;
use Drupal\dbtng_migrator\Model\DatabaseTopology;
use Drupal\dbtng_migrator\Model\ResolvedDatabase;
use Drupal\dbtng_migrator\Model\ResolvedTopology;

/**
 * Resolves role connections and validates declared engines against reality. */
final class DatabaseTopologyResolver implements DatabaseTopologyResolverInterface {

  public function __construct(
    private readonly DatabaseConnectionFactoryInterface $connectionFactory,
    private readonly RuntimeTopologySettingsInterface $runtimeSettings,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function resolve(): ResolvedTopology {
    $settings = $this->runtimeSettings->getAll();
    $primaryKey = (string) ($settings['primary_connection_key'] ?? 'default');
    $standbyKey = (string) ($settings['standby_connection_key'] ?? 'dbtng_standby');
    if ($primaryKey === '' || $standbyKey === '' || $primaryKey === $standbyKey) {
      throw new DbtngException('Primary and standby connection keys must be present and distinct.');
    }

    $primaryConnection = $this->connectionFactory->get($primaryKey);
    $standbyConnection = $this->connectionFactory->get($standbyKey);
    $primary = $this->describe($primaryConnection, DatabaseRole::Primary, $primaryKey);
    $standby = $this->describe($standbyConnection, DatabaseRole::Standby, $standbyKey);

    foreach ([[$primary, 'primary_engine'], [$standby, 'standby_engine']] as [$database, $expectedSetting]) {
      $expected = $settings[$expectedSetting] ?? NULL;
      if (!is_string($expected) || $expected === '') {
        throw new DbtngException(sprintf('The settings.php metadata "%s" is required.', $expectedSetting));
      }
      $expectedEngine = $this->normalizeExpectedEngine($expected);
      if ($database->engine !== $expectedEngine) {
        throw new DbtngException(sprintf(
          'Configured %s engine "%s" does not match the actual %s driver.',
          $database->role->value,
          $expected,
          $database->engine->value,
        ));
      }
    }

    if ($primary->identity === $standby->identity) {
      throw new DbtngException('Primary and standby point to the same physical database.');
    }
    $topology = new DatabaseTopology(
      $primary->engine,
      $standby->engine,
      $primaryKey,
      $standbyKey,
    );

    $profileValue = $this->configFactory->get('dbtng_migrator.settings')->get('profile') ?: 'full';
    $profile = ReplicationProfile::tryFrom((string) $profileValue);
    if ($profile === NULL || !$topology->supportsProfile($profile)) {
      throw new DbtngException('Configured replication profile is invalid for the resolved standby.');
    }

    return new ResolvedTopology($topology, $primary, $standby, $profile);
  }

  private function describe(Connection $connection, DatabaseRole $role, string $key): ResolvedDatabase {
    $driver = strtolower($connection->driver());
    $options = $connection->getConnectionOptions();
    if (in_array($driver, ['sqlite', 'sqlite3'], TRUE)) {
      $version = (string) $this->scalar($connection, 'SELECT sqlite_version()');
      $path = (string) ($options['database'] ?? '');
      return new ResolvedDatabase(
        $role,
        $key,
        $connection,
        DatabaseEngine::Sqlite,
        DatabaseProduct::Sqlite,
        $version,
        $this->sqliteIdentity($path, $connection),
        $path,
      );
    }
    if ($driver !== 'mysql') {
      throw new DbtngException(sprintf('Unsupported database driver "%s" on the %s connection.', $driver, $role->value));
    }

    $version = (string) $this->scalar($connection, 'SELECT VERSION()');
    $product = stripos($version, 'mariadb') !== FALSE ? DatabaseProduct::MariaDb : DatabaseProduct::Mysql;
    $database = (string) ($options['database'] ?? '');
    $hostIdentity = (string) ($options['unix_socket'] ?? $options['host'] ?? 'localhost');
    $port = (string) ($options['port'] ?? '');
    $identity = 'mysql:' . strtolower($hostIdentity) . ':' . $port . ':' . $database;

    return new ResolvedDatabase(
      $role,
      $key,
      $connection,
      DatabaseEngine::MysqlFamily,
      $product,
      $version,
      $identity,
    );
  }

  private function normalizeExpectedEngine(string $engine): DatabaseEngine {
    return match (strtolower($engine)) {
      'mysql', 'mariadb', 'mysql_family' => DatabaseEngine::MysqlFamily,
      'sqlite', 'sqlite3' => DatabaseEngine::Sqlite,
      default => throw new DbtngException(sprintf('Unsupported expected database engine "%s".', $engine)),
    };
  }

  private function sqliteIdentity(string $path, Connection $connection): string {
    if ($path === '' || $path === ':memory:') {
      return 'sqlite:memory:' . spl_object_id($connection);
    }
    $realPath = realpath($path);
    if ($realPath !== FALSE) {
      return 'sqlite:' . $realPath;
    }
    $directory = realpath(dirname($path));
    return 'sqlite:' . ($directory === FALSE ? dirname($path) : $directory) . '/' . basename($path);
  }

  private function scalar(Connection $connection, string $query): mixed {
    $statement = $connection->query($query);
    if ($statement === NULL) {
      throw new DbtngException('The database did not return a statement for a topology check.');
    }
    return $statement->fetchField();
  }

}
