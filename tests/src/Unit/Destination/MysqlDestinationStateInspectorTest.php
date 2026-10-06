<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Destination;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\dbtng_migrator\Destination\Mysql\MysqlDestinationStateInspector;
use Drupal\dbtng_migrator\Model\DestinationState;
use PHPUnit\Framework\TestCase;

/**
 * Tests MySQL destination state inspection. */
final class MysqlDestinationStateInspectorTest extends TestCase {

  public function testEmptyAndAllUserObjectCategoriesAreDetected(): void {
    $inspector = new MysqlDestinationStateInspector();
    $empty = $inspector->inspect($this->connection([]));
    self::assertSame(DestinationState::Empty, $empty->state);
    self::assertSame([], $empty->reasons);

    $populated = $inspector->inspect($this->connection([
      'TABLES' => [
        ['TABLE_NAME' => 'drupal_users', 'TABLE_TYPE' => 'BASE TABLE'],
        ['TABLE_NAME' => 'drupal_report', 'TABLE_TYPE' => 'VIEW'],
      ],
      'TRIGGERS' => [['TRIGGER_NAME' => 'user_trigger']],
      'ROUTINES' => [['ROUTINE_NAME' => 'migrate_users', 'ROUTINE_TYPE' => 'PROCEDURE']],
      'EVENTS' => [['EVENT_NAME' => 'expire_sessions']],
    ]));

    self::assertSame(DestinationState::NonEmpty, $populated->state);
    self::assertEqualsCanonicalizing([
      'base table: drupal_users',
      'view: drupal_report',
      'trigger: user_trigger',
      'procedure: migrate_users',
      'event: expire_sessions',
    ], $populated->reasons);
  }

  /**
   * Creates a mock information-schema connection.
   *
   * @param array<string, list<array<string, mixed>>> $rows
   *   Catalog rows keyed by query category.
   */
  private function connection(array $rows): Connection {
    $connection = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['driver', 'getConnectionOptions', 'query'])
      ->getMockForAbstractClass();
    $connection->method('driver')->willReturn('mysql');
    $connection->method('getConnectionOptions')->willReturn(['database' => 'standby']);
    $connection->method('query')->willReturnCallback(function (string $sql) use ($rows): StatementInterface {
      $statement = $this->createMock(StatementInterface::class);
      foreach (['TABLES', 'TRIGGERS', 'ROUTINES', 'EVENTS'] as $catalog) {
        if (str_contains($sql, 'information_schema.' . $catalog)) {
          $statement->method('fetchAll')->willReturn($rows[$catalog] ?? []);
          return $statement;
        }
      }
      self::fail('Unexpected destination inventory query.');
    });
    return $connection;
  }

}
