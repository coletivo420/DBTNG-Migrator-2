<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Connection;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\DatabaseConnectionFactoryInterface;
use Drupal\dbtng_migrator\Contract\RuntimeTopologySettingsInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseProduct;
use PHPUnit\Framework\TestCase;

/**
 * Tests role-aware topology resolution. */
final class DatabaseTopologyResolverTest extends TestCase {

  public function testDefaultMysqlPrimaryIsResolvedWithoutChangingGlobalRole(): void {
    $primary = $this->connection('mysql', ['database' => 'main', 'host' => 'localhost', 'port' => '3306'], '11.8.6-MariaDB');
    $standby = $this->connection('sqlite', ['database' => '/private/standby.sqlite', 'prefix' => ''], '3.46.1');
    $resolver = $this->resolver(['default' => $primary, 'dbtng_standby' => $standby], [
      'primary_engine' => 'mysql',
      'standby_engine' => 'sqlite',
      'primary_connection_key' => 'default',
      'standby_connection_key' => 'dbtng_standby',
    ]);

    $topology = $resolver->resolve();

    self::assertSame(DatabaseEngine::MysqlFamily, $topology->topology->primaryEngine);
    self::assertSame(DatabaseProduct::MariaDb, $topology->primary->product);
    self::assertSame(DatabaseEngine::Sqlite, $topology->standby->engine);
    self::assertSame('default', $topology->primary->connectionKey);
  }

  public function testSqlitePrimaryIsSupported(): void {
    $primary = $this->connection('sqlite', ['database' => '/private/primary.sqlite', 'prefix' => ''], '3.46.1');
    $standby = $this->connection('mysql', ['database' => 'standby', 'host' => 'localhost', 'port' => '3306'], '8.4.2');
    $resolver = $this->resolver(['default' => $primary, 'dbtng_standby' => $standby], [
      'primary_engine' => 'sqlite',
      'standby_engine' => 'mysql',
    ]);

    $topology = $resolver->resolve();

    self::assertTrue($topology->topology->sqliteIsPrimary());
    self::assertSame(DatabaseProduct::Mysql, $topology->standby->product);
  }

  public function testDriverMismatchFailsClosed(): void {
    $resolver = $this->resolver([
      'default' => $this->connection('sqlite', ['database' => '/private/main.sqlite'], '3.46.1'),
      'dbtng_standby' => $this->connection('mysql', ['database' => 'standby'], '8.4.2'),
    ], ['primary_engine' => 'mysql', 'standby_engine' => 'mysql']);

    $this->expectException(DbtngException::class);
    $resolver->resolve();
  }

  public function testMissingStandbyFailsClosed(): void {
    $resolver = $this->resolver([
      'default' => $this->connection('mysql', ['database' => 'main', 'host' => 'db'], '8.4.2'),
    ], ['primary_engine' => 'mysql', 'standby_engine' => 'sqlite']);

    $this->expectException(DbtngException::class);
    $resolver->resolve();
  }

  public function testDuplicatePhysicalIdentityFailsEvenWhenConnectionKeysDiffer(): void {
    $path = '/private/same.sqlite';
    $resolver = $this->resolver([
      'default' => $this->connection('sqlite', ['database' => $path], '3.46.1'),
      'dbtng_standby' => $this->connection('sqlite', ['database' => $path], '3.46.1'),
    ], ['primary_engine' => 'sqlite', 'standby_engine' => 'sqlite']);

    $this->expectException(DbtngException::class);
    $this->expectExceptionMessage('same physical database');
    $resolver->resolve();
  }

  /**
   * Builds the topology resolver with test connections and settings.
   *
   * @param array<string, Connection> $connections
   * @param array<string, mixed> $runtimeSettings
   */
  private function resolver(array $connections, array $runtimeSettings): DatabaseTopologyResolver {
    $connectionFactory = new class($connections) implements DatabaseConnectionFactoryInterface {

      /** @param array<string, Connection> $connections */
      public function __construct(private readonly array $connections) {}

      public function get(string $connectionKey): Connection {
        if (!isset($this->connections[$connectionKey])) {
          throw new DbtngException('missing');
        }
        return $this->connections[$connectionKey];
      }

    };
    $settings = new class($runtimeSettings) implements RuntimeTopologySettingsInterface {

      /** @param array<string, mixed> $settings */
      public function __construct(private readonly array $settings) {}

      public function getAll(): array {
        return $this->settings;
      }

    };
    $config = $this->createMock(Config::class);
    $config->method('get')->with('profile')->willReturn('full');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('dbtng_migrator.settings')->willReturn($config);
    return new DatabaseTopologyResolver($connectionFactory, $settings, $configFactory);
  }

  /**
   * Creates a fake database connection with product/version responses.
   *
   * @param array<string, mixed> $options
   *   Non-secret connection options.
   */
  private function connection(string $driver, array $options, string $version): Connection {
    $connection = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['driver', 'getConnectionOptions', 'query'])
      ->getMockForAbstractClass();
    $connection->method('driver')->willReturn($driver);
    $connection->method('getConnectionOptions')->willReturn($options);
    $connection->method('query')->willReturnCallback(function () use ($version): StatementInterface {
      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchField')->willReturn($version);
      return $statement;
    });
    return $connection;
  }

}
