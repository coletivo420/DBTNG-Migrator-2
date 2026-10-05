<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Exception;

/**
 * Raised when strict portability cannot preserve source semantics.
 */
final class PortabilityException extends DbtngException {}
