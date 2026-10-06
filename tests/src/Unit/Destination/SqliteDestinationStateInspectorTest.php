<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Destination;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\dbtng_migrator\Destination\Sqlite\SqliteDestinationStateInspector;
use Drupal\dbtng_migrator\Model\DestinationState;
use Drupal\Tests\dbtng_migrator\Unit\Support\DrupalDatabaseUnitTestCase;

/**
 * Tests SQLite destination state inspection. */
final class SqliteDestinationStateInspectorTest extends DrupalDatabaseUnitTestCase {

  public function testEmptyAndPopulatedStatesIncludeReasons(): void {
    $connection = $this->sqliteConnection();
    $inspector = new SqliteDestinationStateInspector();
    self::assertSame(DestinationState::Empty, $inspector->inspect($connection)->state);

    $connection->query('CREATE TABLE content (id INTEGER PRIMARY KEY)');
    $connection->query('CREATE INDEX content_id_idx ON content(id)');
    $sqlite = new \SQLite3((string) $connection->getConnectionOptions()['database']);
    $sqlite->exec('CREATE VIEW content_view AS SELECT id FROM content');
    $sqlite->exec('CREATE TRIGGER content_trigger AFTER INSERT ON content BEGIN UPDATE content SET id = id WHERE id = NEW.id; END');
    $sqlite->close();

    $inspection = $inspector->inspect($connection);
    self::assertSame(DestinationState::NonEmpty, $inspection->state);
    self::assertContains('table: content', $inspection->reasons);
    self::assertContains('index: content_id_idx', $inspection->reasons);
    self::assertContains('view: content_view', $inspection->reasons);
    self::assertContains('trigger: content_trigger', $inspection->reasons);
  }

  public function testCatalogQueryExcludesInternalSqliteNames(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn([]);
    $connection = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['driver', 'query'])
      ->getMockForAbstractClass();
    $connection->method('driver')->willReturn('sqlite');
    $connection->expects(self::once())
      ->method('query')
      ->with(self::stringContains("substr(name, 1, 7) <> 'sqlite_'"))
      ->willReturn($statement);

    self::assertSame(DestinationState::Empty, (new SqliteDestinationStateInspector())->inspect($connection)->state);
  }

}
