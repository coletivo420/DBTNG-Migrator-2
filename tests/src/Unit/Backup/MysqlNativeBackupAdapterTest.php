<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Backup;

use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Backup\MysqlNativeBackupAdapter;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\BackupCompression;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseProduct;
use Drupal\dbtng_migrator\Model\DatabaseRole;
use Drupal\dbtng_migrator\Model\ResolvedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests secure native MySQL backup creation. */
final class MysqlNativeBackupAdapterTest extends TestCase {

  private string $directory;

  private string|false $originalPath;

  protected function setUp(): void {
    $this->directory = sys_get_temp_dir() . '/dbtng-mysql-backup-test-' . bin2hex(random_bytes(5));
    mkdir($this->directory, 0700, TRUE);
    mkdir($this->directory . '/bin', 0700);
    $this->originalPath = getenv('PATH');
  }

  protected function tearDown(): void {
    putenv($this->originalPath === FALSE ? 'PATH' : 'PATH=' . $this->originalPath);
    putenv('DBTNG_TEST_ARGV');
    foreach (glob($this->directory . '/*') ?: [] as $path) {
      if (is_dir($path)) {
        foreach (glob($path . '/*') ?: [] as $child) {
          unlink($child);
        }
        rmdir($path);
      }
      elseif (is_file($path)) {
        unlink($path);
      }
    }
    rmdir($this->directory);
  }

  public function testPrivateGzipArtifactUsesProtectedDefaultsFileAndNoSecretInArgv(): void {
    $argvPath = $this->directory . '/argv.txt';
    $this->installDumpClient(0, $argvPath);

    $artifact = (new MysqlNativeBackupAdapter())->create($this->database(), BackupCompression::Gzip, $this->directory);

    self::assertSame(DatabaseEngine::MysqlFamily, $artifact->engine);
    self::assertSame(DatabaseRole::Primary, $artifact->role);
    self::assertSame('mysql_sql_gzip', $artifact->format->value);
    self::assertSame(hash_file('sha256', $artifact->path), $artifact->sha256);
    self::assertSame(0600, fileperms($artifact->path) & 0777);
    $decoded = gzdecode((string) file_get_contents($artifact->path));
    self::assertStringContainsString('CREATE TABLE backup_probe', (string) $decoded);

    $arguments = (string) file_get_contents($argvPath);
    self::assertStringContainsString('--single-transaction', $arguments);
    self::assertStringContainsString('--quick', $arguments);
    self::assertStringContainsString('600', $arguments);
    self::assertStringContainsString('protected-password-present', $arguments);
    self::assertStringNotContainsString('test-only-password', $arguments);
    self::assertMatchesRegularExpression('/--defaults-extra-file=([^\n]+)/', $arguments);
    $matchResult = preg_match('/--defaults-extra-file=([^\n]+)/', $arguments, $matches);
    self::assertSame(1, $matchResult);
    self::assertArrayHasKey(1, $matches);
    self::assertFileDoesNotExist($matches[1]);
  }

  public function testFailedDumpCleansArtifactsAndDoesNotExposeCredentials(): void {
    $argvPath = $this->directory . '/argv-failure.txt';
    $this->installDumpClient(17, $argvPath);
    $adapter = new MysqlNativeBackupAdapter();

    try {
      $adapter->create($this->database(), BackupCompression::None, $this->directory);
      self::fail('Expected a failed native dump process to be rejected.');
    }
    catch (DbtngException $exception) {
      self::assertStringContainsString('exit code 17', $exception->getMessage());
      self::assertStringNotContainsString('test-only-password', $exception->getMessage());
    }
    self::assertCount(2, glob($this->directory . '/*') ?: []);
    self::assertStringNotContainsString('test-only-password', (string) file_get_contents($argvPath));
  }

  private function database(): ResolvedDatabase {
    $connection = $this->createMock(Connection::class);
    $connection->method('getConnectionOptions')->willReturn([
      'database' => 'drupal_test',
      'username' => 'drupal_user',
      'password' => 'test-only-password',
      'host' => '127.0.0.1',
      'port' => '3306',
    ]);
    return new ResolvedDatabase(
      DatabaseRole::Primary,
      'default',
      $connection,
      DatabaseEngine::MysqlFamily,
      DatabaseProduct::MariaDb,
      '11.8.6-MariaDB',
      'mysql:127.0.0.1:3306:drupal_test',
    );
  }

  private function installDumpClient(int $exitCode, string $argvPath): void {
    $scriptPath = $this->directory . '/bin/mariadb-dump';
    $script = <<< 'SH'
#!/bin/sh
printf '%s\n' "$@" > "$DBTNG_TEST_ARGV"
credential_path=${1#--defaults-extra-file=}
stat -c 'credential-mode=%a' "$credential_path" >> "$DBTNG_TEST_ARGV"
if grep -q 'test-only-password' "$credential_path"; then
  printf '%s\n' 'protected-password-present' >> "$DBTNG_TEST_ARGV"
fi
printf '%s\n' 'CREATE TABLE backup_probe (id INTEGER);'
exit EXIT_CODE
SH;
    $script = str_replace('EXIT_CODE', (string) $exitCode, $script);
    file_put_contents($scriptPath, $script);
    chmod($scriptPath, 0700);
    putenv('PATH=' . $this->directory . '/bin' . PATH_SEPARATOR . (string) $this->originalPath);
    putenv('DBTNG_TEST_ARGV=' . $argvPath);
  }

}
