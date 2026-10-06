<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Support;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use PHPUnit\Framework\TestCase;

/**
 * Provides temporary Drupal SQLite connections to integration-style unit tests. */
abstract class DrupalDatabaseUnitTestCase extends TestCase {

  /**
   * Registered test connection keys.
   *
   * @var list<string>
   */
  private array $connectionKeys = [];

  /**
   * Temporary SQLite database paths.
   *
   * @var list<string>
   */
  private array $databaseFiles = [];

  protected function sqliteConnection(?string $path = NULL): Connection {
    $loader = require dirname(__DIR__, 4) . '/vendor/autoload.php';
    $loader->addPsr4('Drupal\\sqlite\\', dirname(__DIR__, 4) . '/vendor/drupal/core/modules/sqlite/src');
    $path ??= tempnam(sys_get_temp_dir(), 'dbtng-test-sqlite-');
    if ($path === FALSE) {
      throw new \RuntimeException('Unable to create a temporary SQLite test file.');
    }
    $this->databaseFiles[] = $path;
    $key = 'dbtng_test_' . bin2hex(random_bytes(5));
    $this->connectionKeys[] = $key;
    Database::addConnectionInfo($key, 'default', [
      'driver' => 'sqlite',
      'database' => $path,
      'prefix' => '',
    ]);
    return Database::getConnection('default', $key);
  }

  protected function tearDown(): void {
    foreach ($this->connectionKeys as $key) {
      Database::closeConnection(NULL, $key);
      Database::removeConnection($key);
    }
    foreach ($this->databaseFiles as $path) {
      foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) {
          unlink($file);
        }
      }
    }
    parent::tearDown();
  }

}
