<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Schema;

use Drupal\Core\Database\Connection;

/**
 * Quotes already-physical identifiers without applying Drupal table prefixes. */
final class SqlIdentifier {

  public static function quote(Connection $connection, string $identifier): string {
    return strtolower($connection->driver()) === 'mysql'
      ? '`' . str_replace('`', '``', $identifier) . '`'
      : '"' . str_replace('"', '""', $identifier) . '"';
  }

}
