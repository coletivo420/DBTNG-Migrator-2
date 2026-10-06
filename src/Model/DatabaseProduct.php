<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Database server product behind a database driver. */
enum DatabaseProduct: string {
  case Mysql = 'mysql';
  case MariaDb = 'mariadb';
  case Sqlite = 'sqlite';
}
