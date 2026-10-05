<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Native backup formats supported by the initial engine pair.
 */
enum NativeBackupFormat: string {
  case MysqlSql = 'mysql_sql';
  case MysqlSqlGzip = 'mysql_sql_gzip';
  case SqliteDatabase = 'sqlite_database';
  case SqliteDatabaseGzip = 'sqlite_database_gzip';

  public function databaseEngine(): DatabaseEngine {
    return match ($this) {
      self::MysqlSql, self::MysqlSqlGzip => DatabaseEngine::MysqlFamily,
      self::SqliteDatabase, self::SqliteDatabaseGzip => DatabaseEngine::Sqlite,
    };
  }

  public function compressed(): bool {
    return match ($this) {
      self::MysqlSqlGzip, self::SqliteDatabaseGzip => TRUE,
      default => FALSE,
    };
  }

}
