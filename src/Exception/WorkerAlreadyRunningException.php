<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Exception;

/**
 * Signals that another continuous DBTNG sync worker owns the lifetime lock.
 */
final class WorkerAlreadyRunningException extends DbtngException {}
