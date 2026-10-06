<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Reconciliation;

use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Reconciliation\TableContentFingerprint;
use Drupal\Tests\dbtng_migrator\Unit\Support\DrupalDatabaseUnitTestCase;

/**
 * Tests streaming row digests and equal-count data drift detection.
 */
final class TableContentFingerprintTest extends DrupalDatabaseUnitTestCase {

  public function testDetectsChangedDataWithUnchangedRowCount(): void {
    $left = $this->sqliteConnection();
    $right = $this->sqliteConnection();
    foreach ([$left, $right] as $connection) {
      $connection->query('CREATE TABLE sample (id INTEGER, body BLOB)');
      $connection->query('INSERT INTO sample VALUES (1, :body)', [':body' => "a\x00b"]);
      $connection->query('INSERT INTO sample VALUES (2, :body)', [':body' => '🌱']);
    }
    $table = new TableDefinition('sample', [
      new ColumnDefinition('id', 'integer', 'integer', FALSE),
      new ColumnDefinition('body', 'blob', 'blob', TRUE),
    ]);
    $fingerprints = new TableContentFingerprint();
    self::assertSame($fingerprints->calculate($left, $table), $fingerprints->calculate($right, $table));

    $right->query('UPDATE sample SET body = :body WHERE id = 2', [':body' => 'different']);
    self::assertSame(2, $fingerprints->calculate($left, $table)['rows']);
    self::assertNotSame($fingerprints->calculate($left, $table)['digest'], $fingerprints->calculate($right, $table)['digest']);
  }

}
