<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Sync;

use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Sync\PrimaryRowReader;
use Drupal\dbtng_migrator\Sync\SqliteStandbyChangeApplier;
use Drupal\dbtng_migrator\Sync\TableReconciler;
use Drupal\Tests\dbtng_migrator\Unit\Support\DrupalDatabaseUnitTestCase;

/**
 * Exercises explicit SQLite row reads, idempotent upserts and table fallback. */
final class SqliteSyncOperationsTest extends DrupalDatabaseUnitTestCase {

  public function testPrimaryReaderAndRepeatedUpsertPreserveDistinctValues(): void {
    $primary = $this->sqliteConnection();
    $standby = $this->sqliteConnection();
    $primary->query('CREATE TABLE payload (id INTEGER PRIMARY KEY, langcode TEXT NOT NULL, value TEXT, body BLOB)');
    $standby->query('CREATE TABLE payload (id INTEGER PRIMARY KEY, langcode TEXT NOT NULL, value TEXT, body BLOB)');
    $primary->query(
      'INSERT INTO payload (id, langcode, value, body) VALUES (:id, :lang, :value, :body)',
      [':id' => 4, ':lang' => 'pt-br', ':value' => NULL, ':body' => "\x00\xFFblob"],
    );
    $table = new TableDefinition('payload', [
      new ColumnDefinition('id', 'integer', 'integer', FALSE),
      new ColumnDefinition('langcode', 'text', 'text', FALSE),
      new ColumnDefinition('value', 'text', 'text', TRUE),
      new ColumnDefinition('body', 'blob', 'blob', TRUE),
    ], ['id']);
    $reader = new PrimaryRowReader();
    $row = $reader->read($primary, $table, ['id' => 4]);
    self::assertIsArray($row);
    self::assertNull($row['value']);
    self::assertSame("\x00\xFFblob", $row['body']);
    self::assertNull($reader->read($primary, $table, ['id' => 99]));

    $applier = new SqliteStandbyChangeApplier();
    $applier->upsert($standby, $table, $row);
    $row['value'] = '';
    $applier->upsert($standby, $table, $row);
    $applier->upsert($standby, $table, $row);
    $storedStatement = $standby->query('SELECT value, body FROM payload WHERE id = 4');
    if ($storedStatement === NULL) {
      self::fail('Expected the upserted SQLite row to exist.');
    }
    $stored = $storedStatement->fetchAssoc();
    if (!is_array($stored)) {
      self::fail('Expected one associative SQLite row.');
    }
    self::assertSame('', $stored['value']);
    self::assertSame("\x00\xFFblob", $stored['body']);

    $applier->delete($standby, $table, ['id' => 4]);
    $applier->delete($standby, $table, ['id' => 4]);
    $countStatement = $standby->query('SELECT COUNT(*) FROM payload');
    self::assertNotNull($countStatement);
    self::assertSame(0, (int) $countStatement->fetchField());
  }

  public function testTableDirtyReplacementIsAtomicAndStreamsRows(): void {
    $primary = $this->sqliteConnection();
    $standby = $this->sqliteConnection();
    $primary->query('CREATE TABLE unkeyed (payload TEXT NOT NULL)');
    $standby->query('CREATE TABLE unkeyed (payload TEXT NOT NULL)');
    $primary->query('INSERT INTO unkeyed (payload) VALUES (:value)', [':value' => 'source one']);
    $primary->query('INSERT INTO unkeyed (payload) VALUES (:value)', [':value' => 'source two']);
    $standby->query('INSERT INTO unkeyed (payload) VALUES (:value)', [':value' => 'old standby']);
    $table = new TableDefinition('unkeyed', [new ColumnDefinition('payload', 'text', 'text', FALSE)]);
    $rows = (new TableReconciler())->reconcile($primary, $standby, $table, new SqliteStandbyChangeApplier());
    self::assertSame(2, $rows);
    $statement = $standby->query('SELECT payload FROM unkeyed ORDER BY payload');
    self::assertNotNull($statement);
    self::assertSame(['source one', 'source two'], $statement->fetchCol());
  }

  public function testTableDirtyFailureRollsBackOldStandbyRows(): void {
    $primary = $this->sqliteConnection();
    $standby = $this->sqliteConnection();
    $primary->query('CREATE TABLE guarded (payload TEXT NOT NULL)');
    $standby->query("CREATE TABLE guarded (payload TEXT NOT NULL CHECK (payload <> 'bad'))");
    $primary->query('INSERT INTO guarded (payload) VALUES (:value)', [':value' => 'bad']);
    $standby->query('INSERT INTO guarded (payload) VALUES (:value)', [':value' => 'old standby']);
    $table = new TableDefinition('guarded', [new ColumnDefinition('payload', 'text', 'text', FALSE)]);

    $failed = FALSE;
    try {
      (new TableReconciler())->reconcile($primary, $standby, $table, new SqliteStandbyChangeApplier());
    }
    catch (\Throwable) {
      $failed = TRUE;
    }
    self::assertTrue($failed, 'A rejected streamed row must abort the table transaction.');
    $statement = $standby->query('SELECT payload FROM guarded');
    self::assertNotNull($statement);
    self::assertSame('old standby', $statement->fetchField());
  }

}
