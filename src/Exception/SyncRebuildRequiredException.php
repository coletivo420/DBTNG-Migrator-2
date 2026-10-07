<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Exception;

/**
 * Signals baseline/schema/profile drift that requires a fresh rebuild.
 */
final class SyncRebuildRequiredException extends SyncBlockedException {}

