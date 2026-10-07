<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Exception;

/**
 * Signals temporary contention with another DBTNG database operation.
 */
final class OperationLockedException extends DbtngException {}
