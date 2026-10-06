<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Reconciliation;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Database\StatementInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\TableDefinition;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;

/**
 * Computes an order-independent, bounded-memory digest of table rows.
 */
final class TableContentFingerprint {

  /**
   * Calculates row count and a multiset digest using a single cursor.
   *
   * @return array{rows: int, digest: string}
   */
  public function calculate(Connection $connection, TableDefinition $table): array {
    $columns = array_values(array_filter($table->columns, static fn ($column): bool => !$column->hidden));
    if ($columns === []) {
      throw new DbtngException(sprintf('Cannot fingerprint table "%s" without visible columns.', $table->name));
    }
    $select = 'SELECT ' . implode(', ', array_map(
      static fn ($column): string => SqlIdentifier::quote($connection, $column->name),
      $columns,
    )) . ' FROM ' . SqlIdentifier::quote($connection, $table->name);
    $statement = $connection->query($select);
    if (!$statement instanceof StatementInterface) {
      throw new DbtngException(sprintf('Unable to stream rows from "%s" for reconciliation.', $table->name));
    }
    $rows = 0;
    $sum = str_repeat('0', 64);
    while (($record = $statement->fetch(FetchAs::Associative)) !== FALSE) {
      if (!is_array($record)) {
        throw new DbtngException(sprintf('Invalid row returned while reconciling "%s".', $table->name));
      }
      $values = [];
      foreach ($columns as $column) {
        $value = $record[$column->name] ?? NULL;
        if ($value === NULL) {
          $values[] = ['null'];
        }
        elseif ($column->portableType === 'integer') {
          $values[] = ['integer', (string) $value];
        }
        elseif ($column->portableType === 'float') {
          $values[] = ['float', sprintf('%.17g', (float) $value)];
        }
        elseif ($column->portableType === 'numeric') {
          $values[] = ['numeric', $this->normalizeDecimal($value, $column->scale ?? 0)];
        }
        else {
          $values[] = [$column->portableType, base64_encode((string) $value)];
        }
      }
      $rowHash = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
      $sum = $this->addHex($sum, $rowHash);
      $rows++;
    }
    return ['rows' => $rows, 'digest' => $sum];
  }

  private function addHex(string $left, string $right): string {
    $sum = '';
    $carry = 0;
    for ($index = 63; $index >= 0; $index--) {
      $digit = hexdec($left[$index]) + hexdec($right[$index]) + $carry;
      $sum = dechex($digit & 0xF) . $sum;
      $carry = $digit >> 4;
    }
    return $sum;
  }

  private function normalizeDecimal(mixed $value, int $scale): string {
    $decimal = sprintf('%.' . $scale . 'F', (float) $value);
    if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $decimal, $parts) !== 1) {
      return $decimal;
    }
    $integer = ltrim($parts[2], '0') ?: '0';
    $fraction = str_pad(substr($parts[3] ?? '', 0, $scale), $scale, '0');
    return ($parts[1] === '-' && ($integer !== '0' || trim($fraction, '0') !== '') ? '-' : '')
      . $integer
      . ($scale > 0 ? '.' . $fraction : '');
  }

}
