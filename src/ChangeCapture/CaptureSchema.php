<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\ChangeCapture;

use Drupal\dbtng_migrator\Model\ChangeOperation;

/**
 * Reserved physical names used by durable change capture.
 */
final class CaptureSchema {

  public const LOG_TABLE = 'dbtng_migrator_change_log';

  public const TRIGGER_PREFIX = 'dbtng_migrator_cdc_';

  public const VERSION = 1;

  public static function triggerName(string $tableName, ChangeOperation $operation): string {
    $suffix = match ($operation) {
      ChangeOperation::Insert => 'i',
      ChangeOperation::Update => 'u',
      ChangeOperation::Delete => 'd',
    };
    return self::TRIGGER_PREFIX . substr(hash('sha256', $tableName), 0, 16) . '_' . $suffix;
  }

}
