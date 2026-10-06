<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Schema;

use Drupal\dbtng_migrator\Exception\PortabilityException;
use Drupal\dbtng_migrator\Model\ColumnDefinition;
use Drupal\dbtng_migrator\Model\DatabaseEngine;
use Drupal\dbtng_migrator\Schema\ImportTypeMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests the explicit cross-engine type mappings. */
final class ImportTypeMapperTest extends TestCase {

  public function testDrupalScalarMappingsForBothTargets(): void {
    $mapper = new ImportTypeMapper();
    self::assertSame('INTEGER', $mapper->type(new ColumnDefinition('id', 'int unsigned', 'integer', FALSE, NULL, TRUE), DatabaseEngine::Sqlite));
    self::assertSame('BIGINT', $mapper->type(new ColumnDefinition('id', 'INTEGER', 'integer', FALSE), DatabaseEngine::MysqlFamily));
    self::assertSame('VARCHAR(64)', $mapper->type(new ColumnDefinition('name', 'varchar(64)', 'varchar', TRUE, NULL, FALSE, 64), DatabaseEngine::Sqlite));
    self::assertSame('DECIMAL(10,2)', $mapper->type(new ColumnDefinition('amount', 'numeric(10,2)', 'numeric', TRUE, NULL, FALSE, NULL, 10, 2), DatabaseEngine::MysqlFamily));
  }

  public function testUnknownTypeFailsClosed(): void {
    $this->expectException(PortabilityException::class);
    (new ImportTypeMapper())->type(new ColumnDefinition('kind', 'enum', 'unknown', TRUE), DatabaseEngine::Sqlite);
  }

  public function testHighPrecisionNumericFailsClosed(): void {
    $this->expectException(PortabilityException::class);
    (new ImportTypeMapper())->type(new ColumnDefinition('amount', 'numeric(30,2)', 'numeric', TRUE, NULL, FALSE, NULL, 30, 2), DatabaseEngine::Sqlite);
  }

  public function testNullableScalarNullDefaultsAreNormalizedButTextIsPreserved(): void {
    $mapper = new ImportTypeMapper();
    self::assertNull($mapper->defaultValue(new ColumnDefinition('id', 'int', 'integer', TRUE, 'NULL')));
    self::assertNull($mapper->defaultValue(new ColumnDefinition('id', 'INTEGER', 'integer', TRUE, "'NULL'")));
    self::assertSame('NULL', $mapper->defaultValue(new ColumnDefinition('label', 'varchar(8)', 'varchar', TRUE, 'NULL')));
  }

}
