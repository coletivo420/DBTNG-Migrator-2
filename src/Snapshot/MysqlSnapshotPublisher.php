<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Snapshot;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Contract\FailureInjectorInterface;
use Drupal\dbtng_migrator\Contract\SnapshotPublisherInterface;
use Drupal\dbtng_migrator\Manifest\StandbyManifestStore;
use Drupal\dbtng_migrator\Model\SnapshotManifest;
use Drupal\dbtng_migrator\Schema\SqlIdentifier;

/**
 * Publishes same-schema InnoDB candidates through one atomic RENAME TABLE. */
final class MysqlSnapshotPublisher implements SnapshotPublisherInterface {

  public function __construct(
    private readonly StandbyManifestStore $manifests,
    private readonly FailureInjectorInterface $failures,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public function publish(CandidateContext $context, SnapshotManifest $manifest): void {
    if (!$context->candidate->canPublish()) {
      throw new DbtngException('An unvalidated MySQL-family candidate cannot be published.');
    }
    if ($context->candidatePrefix === NULL || $context->archivePrefix === NULL) {
      throw new DbtngException('MariaDB publication received an incomplete candidate context.');
    }
    $connection = $context->connection();
    $versionStatement = $connection->query('SELECT VERSION()');
    if ($versionStatement === NULL) {
      throw new DbtngException('Unable to check server support for atomic MariaDB publication.');
    }
    $version = strtolower((string) $versionStatement->fetchField());
    if (str_contains($version, 'mariadb')) {
      preg_match('/^(\d+\.\d+\.\d+)/', $version, $match);
      if (isset($match[1]) && version_compare($match[1], '10.6.1', '<')) {
        throw new DbtngException('Atomic candidate publication requires MariaDB 10.6.1 or newer.');
      }
    }
    else {
      preg_match('/^(\d+\.\d+\.\d+)/', $version, $match);
      if (!isset($match[1]) || version_compare($match[1], '8.0.13', '<')) {
        throw new DbtngException('Atomic candidate publication requires MySQL 8.0.13 or newer.');
      }
    }
    $pairs = [];
    $archivePrefix = $context->archivePrefix;
    foreach ($context->publishedTableNames as $name) {
      $archived = $archivePrefix . substr(hash('sha256', $name), 0, 40);
      if (strlen($archived) > 64) {
        throw new DbtngException('Generated archived table identifier exceeds the server limit.');
      }
      $pairs[] = SqlIdentifier::quote($connection, $name) . ' TO ' . SqlIdentifier::quote($connection, $archived);
    }
    foreach ($context->tableNames->all() as $name => $candidateName) {
      $pairs[] = SqlIdentifier::quote($connection, $candidateName) . ' TO ' . SqlIdentifier::quote($connection, $name);
    }
    $schema = (string) ($connection->getConnectionOptions()['database'] ?? '');
    $stateExists = $connection->query(
      'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name',
      [':schema' => $schema, ':name' => 'dbtng_migrator_snapshot_state'],
    );
    if ($stateExists !== NULL && (int) $stateExists->fetchField() > 0) {
      $stateArchive = $archivePrefix . 'snapshot_state';
      $pairs[] = SqlIdentifier::quote($connection, 'dbtng_migrator_snapshot_state') . ' TO ' . SqlIdentifier::quote($connection, $stateArchive);
    }
    $pairs[] = SqlIdentifier::quote($connection, $context->candidatePrefix . 'snapshot_state')
      . ' TO ' . SqlIdentifier::quote($connection, 'dbtng_migrator_snapshot_state');
    $this->failures->hit('during_publish', ['candidate' => $context->candidate->uuid]);
    $connection->query('RENAME TABLE ' . implode(', ', $pairs));
    try {
      $this->manifests->markVersionPublished($context->manifestIdentity, $context->candidate->uuid);
      $this->manifests->publishVersion($context->manifestIdentity, $context->candidate->uuid);
    }
    catch (\Throwable) {
      $this->loggerFactory->get('dbtng_migrator')->warning('MySQL-family tables were published but the recovery marker could not be refreshed.');
    }
    try {
      $this->applyRetention($connection);
    }
    catch (\Throwable) {
      $this->loggerFactory->get('dbtng_migrator')->warning('MySQL-family generation retention could not remove every expired archive.');
    }
  }

  private function applyRetention(Connection $connection): void {
    $schema = (string) ($connection->getConnectionOptions()['database'] ?? '');
    $statement = $connection->query(
      'SELECT TABLE_NAME, CREATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema',
      [':schema' => $schema],
    );
    if ($statement === NULL) {
      return;
    }
    $groups = [];
    foreach ($statement->fetchAll(FetchAs::Associative) as $row) {
      $name = (string) ($row['TABLE_NAME'] ?? '');
      if (preg_match('/^(dbtngp[0-9a-f]{8}_)/', $name, $match) !== 1) {
        continue;
      }
      $groups[$match[1]]['tables'][] = $name;
      $groups[$match[1]]['time'] = max(
        (int) ($groups[$match[1]]['time'] ?? 0),
        strtotime((string) ($row['CREATE_TIME'] ?? '')) ?: 0,
      );
    }
    $generations = [];
    foreach ($groups as $prefix => $generation) {
      $generations[] = [
        'prefix' => $prefix,
        'tables' => $generation['tables'],
        'time' => $generation['time'],
      ];
    }
    usort($generations, static fn (array $left, array $right): int => $right['time'] <=> $left['time']);
    $archiveKeep = max(0, (int) ($this->configFactory->get('dbtng_migrator.settings')->get('retention.keep') ?? 3) - 1);
    $connection->query('SET FOREIGN_KEY_CHECKS = 0');
    try {
      foreach (array_slice($generations, $archiveKeep) as $generation) {
        foreach ($generation['tables'] as $name) {
          $connection->query('DROP TABLE ' . SqlIdentifier::quote($connection, $name));
        }
      }
    }
    finally {
      $connection->query('SET FOREIGN_KEY_CHECKS = 1');
    }
  }

}
