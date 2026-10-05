<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Describes how table data participates in a replication profile.
 */
enum ReplicationDecision: string {
  case Copy = 'copy';
  case SchemaOnly = 'schema_only';
}
