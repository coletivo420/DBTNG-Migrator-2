<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\ChangeCapture;

use Drupal\dbtng_migrator\ChangeCapture\ChangeIdentityPlanner;
use Drupal\dbtng_migrator\Model\ChangeIdentityKind;
use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\TableDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Tests conservative row-addressability planning.
 */
final class ChangeIdentityPlannerTest extends TestCase {

  public function testPrimaryKeyUsesRowIdentity(): void {
    $table = new TableDefinition('items', [
      new ColumnDefinition('id', 'integer', 'integer', FALSE),
      new ColumnDefinition('langcode', 'varchar(12)', 'varchar', FALSE),
    ], ['id', 'langcode']);

    $identity = (new ChangeIdentityPlanner())->plan($table);

    self::assertSame(ChangeIdentityKind::PrimaryKey, $identity->kind);
    self::assertSame(['id', 'langcode'], $identity->columns);
  }

  public function testMissingPrimaryKeyFallsBackToTableDirty(): void {
    $table = new TableDefinition('events', [
      new ColumnDefinition('payload', 'text', 'text', TRUE),
    ]);

    $identity = (new ChangeIdentityPlanner())->plan($table);

    self::assertSame(ChangeIdentityKind::Table, $identity->kind);
    self::assertSame([], $identity->columns);
  }

  public function testBinaryPrimaryKeyFallsBackToTableDirty(): void {
    $table = new TableDefinition('binary_key', [
      new ColumnDefinition('id', 'blob', 'blob', FALSE),
    ], ['id']);

    self::assertSame(
      ChangeIdentityKind::Table,
      (new ChangeIdentityPlanner())->plan($table)->kind,
    );
  }

}
