<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Exception;

/**
 * Signals an operator-actionable condition that blocks incremental sync.
 */
class SyncBlockedException extends DbtngException {}
