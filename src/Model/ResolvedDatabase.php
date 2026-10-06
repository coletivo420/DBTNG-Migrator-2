<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

use Drupal\Core\Database\Connection;

/**
 * A role connection resolved from Drupal's explicit connection registry. */
final readonly class ResolvedDatabase {

  public function __construct(
    public DatabaseRole $role,
    public string $connectionKey,
    public Connection $connection,
    public DatabaseEngine $engine,
    public DatabaseProduct $product,
    public string $serverVersion,
    public string $identity,
    public ?string $databasePath = NULL,
  ) {}

  public function label(): string {
    return match ($this->product) {
      DatabaseProduct::Mysql => 'MySQL',
      DatabaseProduct::MariaDb => 'MariaDB',
      DatabaseProduct::Sqlite => 'SQLite',
    };
  }

}
