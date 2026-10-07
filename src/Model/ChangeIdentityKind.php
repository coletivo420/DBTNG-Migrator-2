<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Addressability level available for one captured table mutation.
 */
enum ChangeIdentityKind: string {
  case PrimaryKey = 'primary_key';
  case Table = 'table';
}
