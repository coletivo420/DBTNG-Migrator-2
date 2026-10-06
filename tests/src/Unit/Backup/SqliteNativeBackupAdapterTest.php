<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Backup;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Connection;
use Drupal\dbtng_migrator\Backup\SqliteNativeBackupAdapter;
use Drupal\dbtng_migrator\Model\BackupCompression;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseProduct;
use Drupal\dbtng_migrator\Model\DatabaseRole;
use Drupal\dbtng_migrator\Model\ResolvedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests SQLite native backup creation and WAL consistency. */
final class SqliteNativeBackupAdapterTest extends TestCase {

  private string $directory;

  private string $sourcePath;

  protected function setUp(): void {
    $this->directory = sys_get_temp_dir() . '/dbtng-backup-test-' . bin2hex(random_bytes(5));
    mkdir($this->directory, 0700, TRUE);
    $this->sourcePath = $this->directory . '/source.sqlite';
  }

  protected function tearDown(): void {
    foreach (glob($this->directory . '/*') ?: [] as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    rmdir($this->directory);
  }

  public function testGzippedOnlineBackupIncludesCommittedWalDataAndChecksum(): void {
    $writer = new \SQLite3($this->sourcePath);
    $writer->createCollation('NOCASE_UTF8', [Unicode::class, 'strcasecmp']);
    $journalMode = $writer->querySingle('PRAGMA journal_mode=WAL');
    self::assertIsString($journalMode);
    self::assertSame('wal', strtolower($journalMode));
    $writer->exec('PRAGMA wal_autocheckpoint=0');
    $writer->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $writer->exec('CREATE INDEX messages_value ON messages (value COLLATE NOCASE_UTF8)');
    $writer->exec("INSERT INTO messages (id, value) VALUES (1, 'committed before backup')");
    self::assertFileExists($this->sourcePath . '-wal');

    $connection = $this->createMock(Connection::class);
    $connection->method('getConnectionOptions')->willReturn(['database' => $this->sourcePath, 'prefix' => '']);
    $database = new ResolvedDatabase(
      DatabaseRole::Primary,
      'default',
      $connection,
      DatabaseEngine::Sqlite,
      DatabaseProduct::Sqlite,
      '3.46.1',
      'sqlite:' . $this->sourcePath,
      $this->sourcePath,
    );
    $artifact = (new SqliteNativeBackupAdapter())->create($database, BackupCompression::Gzip, $this->directory);
    $writer->close();

    self::assertSame(DatabaseRole::Primary, $artifact->role);
    self::assertSame(DatabaseEngine::Sqlite, $artifact->engine);
    self::assertSame('sqlite_database_gzip', $artifact->format->value);
    self::assertSame(hash_file('sha256', $artifact->path), $artifact->sha256);
    self::assertGreaterThan(0, $artifact->bytes);
    self::assertSame(0600, fileperms($artifact->path) & 0777);
    $decoded = gzdecode((string) file_get_contents($artifact->path));
    self::assertIsString($decoded);
    $snapshotPath = $this->directory . '/verified.sqlite';
    file_put_contents($snapshotPath, $decoded);
    $snapshot = new \SQLite3($snapshotPath, SQLITE3_OPEN_READONLY);
    $snapshot->createCollation('NOCASE_UTF8', [Unicode::class, 'strcasecmp']);
    self::assertSame('ok', $snapshot->querySingle('PRAGMA integrity_check'));
    self::assertSame('committed before backup', $snapshot->querySingle('SELECT value FROM messages WHERE id = 1'));
    $snapshot->close();
  }

}
