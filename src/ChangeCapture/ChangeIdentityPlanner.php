<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\ChangeCapture;

use Drupal\dbtng_migrator\Model\CaptureIdentityDefinition;
use Drupal\dbtng_migrator\Model\ChangeIdentityKind;
use Drupal\dbtng_migrator\Model\TableDefinition;

/**
 * Chooses row-key or table-dirty capture without inventing unstable identities.
 */
final class ChangeIdentityPlanner {

  public function plan(TableDefinition $table): CaptureIdentityDefinition {
    if ($table->primaryKey === []) {
      return new CaptureIdentityDefinition(ChangeIdentityKind::Table);
    }

    $columns = [];
    foreach ($table->columns as $column) {
      $columns[$column->name] = $column;
    }
    foreach ($table->primaryKey as $name) {
      $column = $columns[$name] ?? NULL;
      if ($column === NULL || !in_array($column->logicalType, ['integer', 'varchar', 'text'], TRUE)) {
        return new CaptureIdentityDefinition(ChangeIdentityKind::Table);
      }
    }

    return new CaptureIdentityDefinition(ChangeIdentityKind::PrimaryKey, $table->primaryKey);
  }

}
