<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Schema;

use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\IndexColumnDefinition;
use Drupal\dbtng_migrator\Model\IndexDefinition;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Schema\ImportTypeMapper;
use Drupal\dbtng_migrator\Schema\SqliteSchemaBuilder;
use Drupal\Tests\dbtng_migrator\Unit\Support\DrupalDatabaseUnitTestCase;

/**
 * Tests normalized schema creation on a real SQLite database. */
final class SqliteSchemaBuilderTest extends DrupalDatabaseUnitTestCase {

  public function testPrimaryUniqueIndexCollationAndAutoincrementAreBuilt(): void {
    $connection = $this->sqliteConnection();
    $table = new TableDefinition(
      'items',
      [
        new ColumnDefinition('id', 'int unsigned', 'integer', FALSE, NULL, TRUE, NULL, NULL, NULL, TRUE),
        new ColumnDefinition('name', 'varchar(80)', 'varchar', FALSE, NULL, FALSE, 80, NULL, NULL, FALSE, FALSE, NULL, 'utf8mb4', 'utf8mb4_general_ci'),
        new ColumnDefinition('weight', 'decimal(8,2)', 'numeric', TRUE, NULL, FALSE, NULL, 8, 2),
      ],
      ['id'],
      ['item_name_unique' => ['name']],
      [],
      indexDefinitions: [
        new IndexDefinition('PRIMARY', TRUE, TRUE, [new IndexColumnDefinition('id', 1)]),
        new IndexDefinition('item_name_unique', TRUE, FALSE, [new IndexColumnDefinition('name', 1)]),
      ],
      flags: ['autoincrement' => TRUE],
    );

    $builder = new SqliteSchemaBuilder(new ImportTypeMapper());
    $builder->createTables($connection, new DatabaseInventory(DatabaseEngine::MysqlFamily, [$table]));
    $builder->createIndexes($connection, new DatabaseInventory(DatabaseEngine::MysqlFamily, [$table]));
    $connection->query("INSERT INTO items (id, name, weight) VALUES (7, 'Café', '12.34')");
    $connection->query("INSERT INTO items (name, weight) VALUES ('Outro', '0.00')");

    $statement = $connection->query('SELECT id FROM items WHERE name = :name', [':name' => 'Outro']);
    self::assertNotNull($statement);
    self::assertSame(8, (int) $statement->fetchField());
    $statement = $connection->query("SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = 'items'");
    self::assertNotNull($statement);
    $createSql = (string) $statement->fetchField();
    self::assertStringContainsString('COLLATE NOCASE_UTF8', $createSql);
    $statement = $connection->query("SELECT COUNT(*) FROM sqlite_schema WHERE type = 'index' AND name LIKE 'dbtng_%'");
    self::assertNotNull($statement);
    self::assertSame(1, (int) $statement->fetchField());
  }

}
