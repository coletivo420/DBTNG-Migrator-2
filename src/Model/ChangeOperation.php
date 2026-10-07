<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Logical mutation type recorded by primary-side change capture.
 */
enum ChangeOperation: string {
  case Insert = 'insert';
  case Update = 'update';
  case Delete = 'delete';
}
