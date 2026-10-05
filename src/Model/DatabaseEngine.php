<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Database engines supported by the initial DBTNG topology.
 */
enum DatabaseEngine: string {
  case MysqlFamily = 'mysql';
  case Sqlite = 'sqlite';

  public function label(): string {
    return match ($this) {
      self::MysqlFamily => 'MariaDB/MySQL',
      self::Sqlite => 'SQLite',
    };
  }

}
