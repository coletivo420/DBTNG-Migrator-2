<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Exception;

/**
 * Raised when a bootstrap import target contains user data or schema objects.
 */
final class DestinationNotEmptyException extends DbtngException {}
