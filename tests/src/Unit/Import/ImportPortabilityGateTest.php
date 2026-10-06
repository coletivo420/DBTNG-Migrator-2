<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Import;

use Drupal\dbtng_migrator\Exception\PortabilityException;
use Drupal\dbtng_migrator\Import\ImportPortabilityGate;
use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\IndexColumnDefinition;
use Drupal\dbtng_migrator\Model\IndexDefinition;
use Drupal\dbtng_migrator\Model\PortabilityIssue;
use Drupal\dbtng_migrator\Model\PortabilityReport;
use Drupal\dbtng_migrator\Model\TableDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Ensures expected physical warnings remain visible but unsupported cases block. */
final class ImportPortabilityGateTest extends TestCase {

  public function testUnsignedAndMappedMysqlCollationsAreReviewWarningsNotGenericBlockers(): void {
    $table = new TableDefinition('records', [
      new ColumnDefinition('id', 'int unsigned', 'integer', FALSE, NULL, TRUE),
      new ColumnDefinition('label', 'varchar(50)', 'varchar', TRUE, NULL, FALSE, 50, NULL, NULL, FALSE, FALSE, NULL, 'utf8mb4', 'utf8mb4_general_ci'),
    ], collation: 'utf8mb4_general_ci');
    $inventory = new DatabaseInventory(DatabaseEngine::MysqlFamily, [$table]);
    $warnings = new PortabilityReport([
      new PortabilityIssue('unsigned_semantics', 'Unsigned range review.', 'warning'),
      new PortabilityIssue('column_collation', 'Mapped case-insensitive collation.', 'warning'),
      new PortabilityIssue('table_collation', 'Mapped default collation.', 'warning'),
    ]);

    (new ImportPortabilityGate())->assertImportable($inventory, DatabaseEngine::Sqlite, $warnings);
    self::assertSame(3, $warnings->countBySeverity('warning'));
  }

  public function testUniquePrefixIndexBlocksImport(): void {
    $table = new TableDefinition(
      'records',
      [new ColumnDefinition('label', 'varchar(255)', 'varchar', FALSE, NULL, FALSE, 255)],
      indexDefinitions: [new IndexDefinition('label_unique_prefix', TRUE, FALSE, [new IndexColumnDefinition('label', 1, 16)])],
    );
    $this->expectException(PortabilityException::class);
    (new ImportPortabilityGate())->assertImportable(
      new DatabaseInventory(DatabaseEngine::MysqlFamily, [$table]),
      DatabaseEngine::Sqlite,
      new PortabilityReport([new PortabilityIssue('prefix_index', 'Review prefix index.', 'warning')]),
    );
  }

}
