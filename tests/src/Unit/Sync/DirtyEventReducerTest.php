<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Sync;

use Drupal\dbtng_migrator\Model\ChangeIdentityKind;
use Drupal\dbtng_migrator\Model\ChangeOperation;
use Drupal\dbtng_migrator\Model\ChangeRecord;
use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Policy\CleanReplicationPolicy;
use Drupal\dbtng_migrator\Sync\DirtyEventReducer;
use PHPUnit\Framework\TestCase;

/**
 * Tests exact-ID dirty-event reduction and canonical primary keys. */
final class DirtyEventReducerTest extends TestCase {

  public function testDuplicateAndCompositeKeyEventsCollapseInPhysicalKeyOrder(): void {
    $table = new TableDefinition('localized', [
      new ColumnDefinition('entity_id', 'integer', 'integer', FALSE),
      new ColumnDefinition('langcode', 'varchar(12)', 'varchar', FALSE),
      new ColumnDefinition('value', 'text', 'text', TRUE),
    ], ['entity_id', 'langcode']);
    $events = [
      $this->event(
        12,
        ChangeOperation::Update,
        ['langcode' => 'pt-br', 'entity_id' => 7],
        ['entity_id' => 7, 'langcode' => 'en'],
      ),
      $this->event(
        5,
        ChangeOperation::Update,
        ['entity_id' => 7, 'langcode' => 'pt-br'],
        ['langcode' => 'pt-br', 'entity_id' => 7],
      ),
    ];

    $batch = (new DirtyEventReducer(new CleanReplicationPolicy()))->reduce(
      $events,
      new DatabaseInventory(DatabaseEngine::MysqlFamily, [$table]),
      ReplicationProfile::Full,
    );

    self::assertCount(2, $batch->identities);
    self::assertSame([12, 5], $batch->eventIds);
    self::assertSame(['entity_id' => 7, 'langcode' => 'pt-br'], $batch->identities[0]->key);
    self::assertSame([12, 5], $batch->identities[0]->eventIds);
    self::assertSame(['entity_id' => 7, 'langcode' => 'en'], $batch->identities[1]->key);
  }

  public function testTableDirtyPromotesRowsAndPreservesEveryExactEventId(): void {
    $table = new TableDefinition('unkeyed', [new ColumnDefinition('value', 'text', 'text', TRUE)]);
    $events = [
      $this->event(8, ChangeOperation::Insert, ['value' => 1], NULL, ChangeIdentityKind::PrimaryKey, 'unkeyed'),
      $this->event(3, ChangeOperation::Update, NULL, NULL, ChangeIdentityKind::Table, 'unkeyed'),
    ];
    $batch = (new DirtyEventReducer(new CleanReplicationPolicy()))->reduce(
      $events,
      new DatabaseInventory(DatabaseEngine::Sqlite, [$table]),
      ReplicationProfile::Full,
    );

    self::assertCount(1, $batch->identities);
    self::assertSame(ChangeIdentityKind::Table, $batch->identities[0]->kind);
    self::assertSame([8, 3], $batch->identities[0]->eventIds);
    self::assertSame([8, 3], $batch->eventIds);
  }

  public function testCleanSchemaOnlyEventsCollapseToOneTableIdentity(): void {
    $table = new TableDefinition('cache_default', [
      new ColumnDefinition('cid', 'varchar(255)', 'varchar', FALSE),
    ], ['cid']);
    $events = [
      $this->event(2, ChangeOperation::Insert, ['cid' => 'a'], NULL, ChangeIdentityKind::PrimaryKey, 'cache_default'),
      $this->event(9, ChangeOperation::Delete, NULL, ['cid' => 'b'], ChangeIdentityKind::PrimaryKey, 'cache_default'),
    ];
    $batch = (new DirtyEventReducer(new CleanReplicationPolicy()))->reduce(
      $events,
      new DatabaseInventory(DatabaseEngine::MysqlFamily, [$table]),
      ReplicationProfile::Clean,
    );

    self::assertCount(1, $batch->identities);
    self::assertSame(ChangeIdentityKind::Table, $batch->identities[0]->kind);
    self::assertSame([2, 9], $batch->identities[0]->eventIds);
  }

  /**
   * Constructs an immutable capture event for reducer tests.
   *
   * @param array<string, int|string|null>|null $key
   * @param array<string, int|string|null>|null $oldKey
   */
  private function event(
    int $id,
    ChangeOperation $operation,
    ?array $key,
    ?array $oldKey = NULL,
    ChangeIdentityKind $kind = ChangeIdentityKind::PrimaryKey,
    string $table = 'localized',
  ): ChangeRecord {
    return new ChangeRecord($id, $table, $operation, $kind, $key, $oldKey, '2026-10-07T00:00:00Z');
  }

}
