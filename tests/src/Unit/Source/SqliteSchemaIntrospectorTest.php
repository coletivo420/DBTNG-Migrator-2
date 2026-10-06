<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Source;

use Drupal\dbtng_migrator\Model\IndexDefinition;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Source\Sqlite\SqliteSchemaIntrospector;
use Drupal\Tests\dbtng_migrator\Unit\Support\DrupalDatabaseUnitTestCase;

/**
 * Tests physical SQLite schema inventory. */
final class SqliteSchemaIntrospectorTest extends DrupalDatabaseUnitTestCase {

  public function testPhysicalSchemaFeaturesAreInventoried(): void {
    $connection = $this->sqliteConnection();
    $connection->query('PRAGMA foreign_keys = ON');
    $connection->query("CREATE TABLE authors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT 'anonymous', normalized TEXT GENERATED ALWAYS AS (lower(name)) STORED)");
    $connection->query('CREATE UNIQUE INDEX authors_name_unique ON authors(name)');
    $connection->query('CREATE INDEX authors_name_prefix ON authors(name COLLATE NOCASE)');
    $connection->query('CREATE INDEX authors_name_partial ON authors(name) WHERE id > 0');
    $connection->query('CREATE INDEX authors_name_expression ON authors(lower(name))');
    $connection->query('CREATE TABLE editions (author_id INTEGER, edition INTEGER, PRIMARY KEY(author_id, edition), FOREIGN KEY(author_id) REFERENCES authors(id) ON DELETE CASCADE) WITHOUT ROWID');
    $connection->query('CREATE TABLE strict_items (key TEXT PRIMARY KEY) STRICT');
    $connection->query('CREATE VIEW author_view AS SELECT id, name FROM authors');
    $sqlite = new \SQLite3((string) $connection->getConnectionOptions()['database']);
    $sqlite->exec('CREATE TRIGGER author_trigger AFTER INSERT ON authors BEGIN UPDATE authors SET name = name WHERE id = NEW.id; END');
    $sqlite->close();

    $inventory = (new SqliteSchemaIntrospector())->inspect($connection);

    self::assertSame(DatabaseEngine::Sqlite, $inventory->databaseEngine);
    self::assertCount(3, $inventory->tables);
    $authors = $this->table($inventory->tables, 'authors');
    self::assertSame(['id'], $authors->primaryKey);
    self::assertTrue($authors->columns[2]->generated);
    self::assertTrue($authors->columns[2]->hidden);
    self::assertTrue($this->index($authors->indexDefinitions, 'authors_name_unique')->unique);
    self::assertTrue($this->index($authors->indexDefinitions, 'authors_name_expression')->functional);
    self::assertTrue($this->index($authors->indexDefinitions, 'authors_name_partial')->partial);
    self::assertTrue($authors->flags['autoincrement']);

    $editions = $this->table($inventory->tables, 'editions');
    self::assertSame(['author_id', 'edition'], $editions->primaryKey);
    self::assertTrue($editions->flags['without_rowid']);
    self::assertSame('authors', $editions->foreignKeys[0]->referencedTable);
    self::assertSame('CASCADE', $editions->foreignKeys[0]->onDelete);
    self::assertTrue($this->table($inventory->tables, 'strict_items')->flags['strict']);
    self::assertEqualsCanonicalizing(['view', 'trigger'], array_map(static fn ($object): string => $object->type, $inventory->objects));
  }

  public function testQuotedStringDefaultsAreReturnedAsValues(): void {
    $connection = $this->sqliteConnection();
    $connection->query("CREATE TABLE default_values (id INTEGER PRIMARY KEY, quoted TEXT DEFAULT 'it''s portable', plain VARCHAR(12) DEFAULT 'anonymous')");

    $inventory = (new SqliteSchemaIntrospector())->inspect($connection);

    self::assertSame("it's portable", $inventory->tables[0]->columns[1]->default);
    self::assertSame('anonymous', $inventory->tables[0]->columns[2]->default);
  }

  /**
   * Finds an inventoried table by its physical name.
   *
   * @param list<\Drupal\dbtng_migrator\Model\TableDefinition> $tables
   *   Physical table definitions.
   */
  private function table(array $tables, string $name): TableDefinition {
    foreach ($tables as $table) {
      if ($table->name === $name) {
        return $table;
      }
    }
    self::fail(sprintf('Expected table "%s" was not inventoried.', $name));
  }

  /**
   * Finds an inventoried index by its physical name.
   *
   * @param list<\Drupal\dbtng_migrator\Model\IndexDefinition> $indexes
   *   Index definitions.
   */
  private function index(array $indexes, string $name): IndexDefinition {
    foreach ($indexes as $index) {
      if ($index->name === $name) {
        return $index;
      }
    }
    self::fail(sprintf('Expected index "%s" was not inventoried.', $name));
  }

}
