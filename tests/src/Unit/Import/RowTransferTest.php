<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Import;

use Drupal\dbtng_migrator\Import\RowTransfer;
use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Policy\CleanReplicationPolicy;
use Drupal\dbtng_migrator\Schema\ImportTypeMapper;
use Drupal\dbtng_migrator\Schema\SqliteSchemaBuilder;
use Drupal\Tests\dbtng_migrator\Unit\Support\DrupalDatabaseUnitTestCase;

/**
 * Tests bounded row transfer and preservation of distinct SQL values. */
final class RowTransferTest extends DrupalDatabaseUnitTestCase {

  public function testNullEmptyZeroUnicodeAndBinaryValuesArePreserved(): void {
    $source = $this->sqliteConnection();
    $destination = $this->sqliteConnection();
    $source->query('CREATE TABLE payloads (id INTEGER PRIMARY KEY, nullable_text TEXT, empty_text TEXT, count INTEGER, body BLOB)');
    $binary = "\x00\xFFbinary";
    $source->query(
      'INSERT INTO payloads (id, nullable_text, empty_text, count, body) VALUES (:id, :nullable, :empty, :count, :body)',
      [':id' => 1, ':nullable' => NULL, ':empty' => '', ':count' => 0, ':body' => $binary],
    );
    $source->query(
      'INSERT INTO payloads (id, nullable_text, empty_text, count, body) VALUES (:id, :nullable, :empty, :count, :body)',
      [':id' => 2, ':nullable' => '🌱', ':empty' => '', ':count' => 9, ':body' => "\x00"],
    );
    $table = new TableDefinition('payloads', [
      new ColumnDefinition('id', 'integer', 'integer', FALSE),
      new ColumnDefinition('nullable_text', 'text', 'text', TRUE),
      new ColumnDefinition('empty_text', 'text', 'text', TRUE),
      new ColumnDefinition('count', 'integer', 'integer', TRUE),
      new ColumnDefinition('body', 'blob', 'blob', TRUE),
    ], ['id']);
    $inventory = new DatabaseInventory(DatabaseEngine::Sqlite, [$table]);
    (new SqliteSchemaBuilder(new ImportTypeMapper()))->createTables($destination, $inventory);

    $result = (new RowTransfer(new CleanReplicationPolicy()))->transfer(
      $source,
      $destination,
      $inventory,
      ReplicationProfile::Full,
      1,
      4,
    );

    self::assertSame(2, $result['rows']);
    self::assertGreaterThan(4, $result['bytes']);
    $statement = $destination->query('SELECT nullable_text, empty_text, count, body FROM payloads WHERE id = 1');
    self::assertNotNull($statement);
    $row = $statement->fetchAssoc();
    self::assertIsArray($row);
    self::assertNull($row['nullable_text']);
    self::assertSame('', $row['empty_text']);
    self::assertSame(0, (int) $row['count']);
    self::assertSame($binary, $row['body']);
    $statement = $destination->query('SELECT nullable_text FROM payloads WHERE id = 2');
    self::assertNotNull($statement);
    self::assertSame('🌱', $statement->fetchField());
  }

}
