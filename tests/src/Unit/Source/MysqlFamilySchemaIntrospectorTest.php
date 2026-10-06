<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Source;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Source\Mysql\MysqlFamilySchemaIntrospector;
use PHPUnit\Framework\TestCase;

/**
 * Tests physical MySQL-family catalog inventory. */
final class MysqlFamilySchemaIntrospectorTest extends TestCase {

  public function testPhysicalInventoryHonorsPrefixAndCapturesSemantics(): void {
    $rows = [
      'TABLES' => [
        ['TABLE_NAME' => 'drupal_example', 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB',
          'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'TABLE_ROWS' => '7'],
        ['TABLE_NAME' => 'drupal_empty', 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB',
          'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'TABLE_ROWS' => '0'],
        ['TABLE_NAME' => 'drupal_report', 'TABLE_TYPE' => 'VIEW', 'ENGINE' => NULL,
          'TABLE_COLLATION' => NULL, 'TABLE_ROWS' => NULL],
        ['TABLE_NAME' => 'unrelated_users', 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB',
          'TABLE_COLLATION' => NULL, 'TABLE_ROWS' => '100'],
      ],
      'COLUMNS' => [
        $this->column('drupal_example', 'tenant_id', 'int unsigned', 'int', 'NO', NULL, NULL, 10, 0, 'auto_increment', '', NULL, NULL),
        $this->column('drupal_example', 'local_id', 'int', 'int', 'NO', 0, NULL, 10, 0, '', '', NULL, NULL),
        $this->column('drupal_example', 'slug', 'varchar(80)', 'varchar', 'YES', NULL, 80, NULL, NULL, '', '', 'utf8mb4', 'utf8mb4_bin'),
        $this->column('drupal_example', 'amount', 'decimal(12,4)', 'decimal', 'NO', 0, NULL, 12, 4, '', '', NULL, NULL),
        $this->column('drupal_example', 'payload', 'blob', 'blob', 'YES', NULL, 65535, NULL, NULL, '', '', NULL, NULL),
        $this->column('drupal_example', 'computed', 'int', 'int', 'YES', NULL, NULL, 10, 0, 'STORED GENERATED', 'local_id + 1', NULL, NULL),
      ],
      'STATISTICS' => [
        $this->indexRow('drupal_example', 'PRIMARY', 0, 1, 'tenant_id', NULL, 'BTREE'),
        $this->indexRow('drupal_example', 'PRIMARY', 0, 2, 'local_id', NULL, 'BTREE'),
        $this->indexRow('drupal_example', 'slug_unique', 0, 1, 'slug', NULL, 'BTREE'),
        $this->indexRow('drupal_example', 'slug_prefix', 1, 1, 'slug', 12, 'BTREE'),
        $this->indexRow('drupal_example', 'expression_idx', 1, 1, NULL, NULL, 'BTREE'),
      ],
      'KEY_COLUMN_USAGE' => [
        $this->foreignRow('drupal_example', 'tenant_fk', 'tenant_id', 'tenant', 'id', 1),
      ],
      'TRIGGERS' => [['TRIGGER_NAME' => 'example_update', 'EVENT_OBJECT_TABLE' => 'drupal_example']],
      'ROUTINES' => [
        ['ROUTINE_NAME' => 'example_procedure', 'ROUTINE_TYPE' => 'PROCEDURE'],
        ['ROUTINE_NAME' => 'example_function', 'ROUTINE_TYPE' => 'FUNCTION'],
      ],
      'EVENTS' => [['EVENT_NAME' => 'example_event']],
    ];
    $connection = $this->connection($rows, ['database' => 'drupal_db', 'prefix' => 'drupal_']);

    $inventory = (new MysqlFamilySchemaIntrospector())->inspect($connection);

    self::assertSame(DatabaseEngine::MysqlFamily, $inventory->databaseEngine);
    self::assertSame('MariaDB', $inventory->serverProduct);
    self::assertCount(2, $inventory->tables);
    $table = $inventory->tables[0];
    self::assertSame('drupal_example', $table->name);
    self::assertSame(['tenant_id', 'local_id'], $table->primaryKey);
    self::assertTrue($table->columns[0]->unsigned);
    self::assertTrue($table->columns[0]->autoIncrement);
    self::assertSame('varchar', $table->columns[2]->portableType);
    self::assertSame('utf8mb4_bin', $table->columns[2]->collation);
    self::assertSame(12, $table->columns[3]->precision);
    self::assertSame(4, $table->columns[3]->scale);
    self::assertSame('blob', $table->columns[4]->portableType);
    self::assertTrue($table->columns[5]->generated);
    self::assertSame('local_id + 1', $table->columns[5]->generatedExpression);
    self::assertTrue($table->indexDefinitions[3]->functional);
    self::assertSame(12, $table->indexDefinitions[2]->columns[0]->prefixLength);
    self::assertSame('tenant', $table->foreignKeys[0]->referencedTable);
    self::assertSame([], $inventory->tables[1]->columns);
    self::assertSame(['view', 'trigger', 'procedure', 'function', 'event'], array_map(static fn ($object): string => $object->type, $inventory->objects));
  }

  /**
   * Builds a fake MySQL-family Drupal connection and structured catalog rows.
   *
   * @param array<string, list<array<string, mixed>>> $rows
   *   Query results keyed by information_schema table.
   * @param array<string, mixed> $options
   *   Drupal connection options.
   */
  private function connection(array $rows, array $options): Connection {
    $connection = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['driver', 'getConnectionOptions', 'query'])
      ->getMockForAbstractClass();
    $connection->method('driver')->willReturn('mysql');
    $connection->method('getConnectionOptions')->willReturn($options);
    $connection->method('query')->willReturnCallback(function (string $sql) use ($rows): StatementInterface {
      $statement = $this->createMock(StatementInterface::class);
      if (str_contains($sql, 'SELECT VERSION()')) {
        $statement->method('fetchField')->willReturn('11.8.6-MariaDB');
        return $statement;
      }
      foreach (['TABLES', 'COLUMNS', 'STATISTICS', 'KEY_COLUMN_USAGE', 'TRIGGERS', 'ROUTINES', 'EVENTS'] as $catalog) {
        if (str_contains($sql, 'information_schema.' . $catalog)) {
          $statement->method('fetchAll')->willReturn($rows[$catalog] ?? []);
          return $statement;
        }
      }
      self::fail('Unexpected physical schema query.');
    });
    return $connection;
  }

  /**
   * Builds one simulated information_schema column row.
   *
   * @return array<string, mixed>
   *   Physical column catalog row.
   */
  private function column(string $table, string $name, string $type, string $dataType, string $nullable, mixed $default, ?int $length, ?int $precision, ?int $scale, string $extra, string $expression, ?string $charset, ?string $collation): array {
    return [
      'TABLE_NAME' => $table,
      'COLUMN_NAME' => $name,
      'COLUMN_TYPE' => $type,
      'DATA_TYPE' => $dataType,
      'IS_NULLABLE' => $nullable,
      'COLUMN_DEFAULT' => $default,
      'CHARACTER_MAXIMUM_LENGTH' => $length,
      'NUMERIC_PRECISION' => $precision,
      'NUMERIC_SCALE' => $scale,
      'EXTRA' => $extra,
      'GENERATION_EXPRESSION' => $expression,
      'CHARACTER_SET_NAME' => $charset,
      'COLLATION_NAME' => $collation,
    ];
  }

  /**
   * Builds one simulated information_schema index row.
   *
   * @return array<string, mixed>
   *   Physical index catalog row.
   */
  private function indexRow(string $table, string $name, int $nonUnique, int $position, ?string $column, ?int $prefix, string $type): array {
    return [
      'TABLE_NAME' => $table,
      'INDEX_NAME' => $name,
      'NON_UNIQUE' => $nonUnique,
      'SEQ_IN_INDEX' => $position,
      'COLUMN_NAME' => $column,
      'SUB_PART' => $prefix,
      'INDEX_TYPE' => $type,
    ];
  }

  /**
   * Builds one simulated information_schema foreign-key row.
   *
   * @return array<string, mixed>
   *   Physical foreign-key catalog row.
   */
  private function foreignRow(string $table, string $constraint, string $column, string $referencedTable, string $referencedColumn, int $position): array {
    return [
      'TABLE_NAME' => $table,
      'CONSTRAINT_NAME' => $constraint,
      'COLUMN_NAME' => $column,
      'REFERENCED_TABLE_NAME' => $referencedTable,
      'REFERENCED_COLUMN_NAME' => $referencedColumn,
      'ORDINAL_POSITION' => $position,
      'UPDATE_RULE' => 'CASCADE',
      'DELETE_RULE' => 'RESTRICT',
      'MATCH_OPTION' => 'NONE',
    ];
  }

}
