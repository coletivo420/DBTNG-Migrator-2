<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Exception;

/**
 * Signals a retryable operational database failure.
 */
final class SyncTransientException extends DbtngException {}
