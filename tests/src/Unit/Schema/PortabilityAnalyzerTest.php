<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Schema;

use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Model\DatabaseInventory;
use Drupal\dbtng_migrator\Model\DatabaseObjectDefinition;
use Drupal\dbtng_migrator\Model\IndexColumnDefinition;
use Drupal\dbtng_migrator\Model\IndexDefinition;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Schema\PortabilityAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Tests initial strict portability findings. */
final class PortabilityAnalyzerTest extends TestCase {

  public function testSupportedDrupalEquivalentTypesArePortable(): void {
    $table = new TableDefinition('content', [
      new ColumnDefinition('id', 'int', 'integer', FALSE),
      new ColumnDefinition('name', 'varchar(64)', 'varchar', TRUE),
      new ColumnDefinition('body', 'text', 'text', TRUE),
      new ColumnDefinition('data', 'blob', 'blob', TRUE),
      new ColumnDefinition('amount', 'decimal(10,2)', 'numeric', FALSE, NULL, FALSE, NULL, 10, 2),
      new ColumnDefinition('ratio', 'double', 'float', TRUE),
    ]);

    self::assertTrue((new PortabilityAnalyzer())->analyze(new DatabaseInventory(DatabaseEngine::MysqlFamily, [$table]))->isPortable());
  }

  public function testWarningsAndStrictBlockersAreDistinct(): void {
    $table = new TableDefinition(
      'records',
      [
        new ColumnDefinition('count', 'int unsigned', 'integer', FALSE, NULL, TRUE),
        new ColumnDefinition('kind', "enum('a','b')", 'unknown', FALSE),
        new ColumnDefinition('computed', 'int', 'integer', TRUE, NULL, FALSE, NULL, NULL, NULL, FALSE, TRUE),
      ],
      indexDefinitions: [
        new IndexDefinition('prefix_idx', FALSE, FALSE, [new IndexColumnDefinition('count', 1, 4)]),
        new IndexDefinition('function_idx', FALSE, FALSE, [new IndexColumnDefinition(NULL, 1)], functional: TRUE),
      ],
    );
    $inventory = new DatabaseInventory(
      DatabaseEngine::MysqlFamily,
      [$table],
      [new DatabaseObjectDefinition('trigger', 'maintain_records', 'records')],
    );

    $report = (new PortabilityAnalyzer())->analyze($inventory);

    self::assertFalse($report->isPortable());
    self::assertGreaterThanOrEqual(1, $report->countBySeverity('warning'));
    self::assertGreaterThanOrEqual(4, $report->countBySeverity('error'));
    self::assertContains('generated_column', array_map(static fn ($issue): string => $issue->code, $report->issues));
    self::assertContains('functional_index', array_map(static fn ($issue): string => $issue->code, $report->issues));
  }

}
