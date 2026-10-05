<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Initialization state of a configured import destination.
 */
enum DestinationState: string {
  case Empty = 'empty';
  case NonEmpty = 'non_empty';
}
