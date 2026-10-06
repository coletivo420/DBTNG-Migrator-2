<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Schema;

/**
 * Normalizes common physical declarations without hiding unknown semantics. */
final class PhysicalTypeMapper {

  public static function logicalType(string $dataType): string {
    return match (strtolower($dataType)) {
      'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'boolean', 'bool' => 'integer',
      'char', 'varchar', 'nchar', 'nvarchar' => 'varchar',
      'text', 'tinytext', 'mediumtext', 'longtext', 'clob' => 'text',
      'binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'bytea' => 'blob',
      'float', 'double', 'real' => 'float',
      'decimal', 'numeric' => 'numeric',
      'date' => 'date',
      'datetime' => 'datetime',
      'timestamp' => 'timestamp',
      'time' => 'time',
      default => 'unknown',
    };
  }

}
