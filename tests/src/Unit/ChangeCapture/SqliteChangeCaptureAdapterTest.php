<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\ChangeCapture;

use Drupal\dbtng_migrator\ChangeCapture\CaptureSchema;
use Drupal\dbtng_migrator\ChangeCapture\ChangeIdentityPlanner;
use Drupal\dbtng_migrator\ChangeCapture\SqliteChangeCaptureAdapter;
use Drupal\dbtng_migrator\Model\ChangeIdentityKind;
use Drupal\dbtng_migrator\Model\ChangeOperation;
use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseObjectDefinition;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Source\Sqlite\SqliteSchemaIntrospector;
use Drupal\Tests\dbtng_migrator\Unit\Support\DrupalDatabaseUnitTestCase;

/**
 * Tests durable SQLite trigger capture and exact acknowledgement.
 */
final class SqliteChangeCaptureAdapterTest extends DrupalDatabaseUnitTestCase {

  public function testCaptureLifecycleAndEvents(): void {
    $connection = $this->sqliteConnection();
    $connection->query('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $connection->query('CREATE TABLE no_key (payload TEXT)');
    $inventory = new DatabaseInventory(DatabaseEngine::Sqlite, [
      new TableDefinition('items', [
        new ColumnDefinition('id', 'integer', 'integer', FALSE),
        new ColumnDefinition('name', 'text', 'text', FALSE),
      ], ['id']),
      new TableDefinition('no_key', [
        new ColumnDefinition('payload', 'text', 'text', TRUE),
      ]),
    ]);
    $adapter = new SqliteChangeCaptureAdapter(new ChangeIdentityPlanner());

    $status = $adapter->install($connection, $inventory);
    self::assertTrue($status->installed);
    self::assertTrue($status->healthy);
    self::assertSame(6, $status->installedTriggers);
    self::assertSame(1, $status->tableDirtyTables);

    $client = $connection->getClientConnection();
    self::assertInstanceOf(\PDO::class, $client);
    $client->beginTransaction();
    $client->exec("INSERT INTO items (id, name) VALUES (99, 'rolled back')");
    $client->rollBack();
    self::assertCount(0, $adapter->pending($connection, 10));

    $connection->query("INSERT INTO items (id, name) VALUES (1, 'one')");
    $connection->query("UPDATE items SET id = 2, name = 'two' WHERE id = 1");
    $connection->query('DELETE FROM items WHERE id = 2');
    $connection->query("INSERT INTO no_key (payload) VALUES ('dirty')");

    $records = $adapter->pending($connection, 10);
    self::assertCount(4, $records);
    self::assertSame(ChangeOperation::Insert, $records[0]->operation);
    self::assertSame(ChangeIdentityKind::PrimaryKey, $records[0]->identityKind);
    self::assertSame(['id' => 1], $records[0]->key);
    self::assertNull($records[0]->oldKey);

    self::assertSame(ChangeOperation::Update, $records[1]->operation);
    self::assertSame(['id' => 2], $records[1]->key);
    self::assertSame(['id' => 1], $records[1]->oldKey);

    self::assertSame(ChangeOperation::Delete, $records[2]->operation);
    self::assertNull($records[2]->key);
    self::assertSame(['id' => 2], $records[2]->oldKey);

    self::assertSame(ChangeIdentityKind::Table, $records[3]->identityKind);
    self::assertNull($records[3]->key);

    $adapter->acknowledge($connection, [$records[0]->id, $records[2]->id]);
    self::assertCount(2, $adapter->pending($connection, 10));

    $visible = (new SqliteSchemaIntrospector())->inspect($connection);
    self::assertSame(['items', 'no_key'], array_map(
      static fn (TableDefinition $table): string => $table->name,
      $visible->tables,
    ));
    self::assertSame([], array_values(array_filter(
      $visible->objects,
      static fn (DatabaseObjectDefinition $object): bool => str_starts_with($object->name, CaptureSchema::TRIGGER_PREFIX),
    )));

    $adapter->uninstall($connection);
    self::assertFalse($adapter->status($connection, $inventory)->installed);
    self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM no_key')?->fetchField());
  }

}
