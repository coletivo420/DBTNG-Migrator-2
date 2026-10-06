<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Snapshot;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\dbtng_migrator\Model\StandbyCandidate;
use Drupal\dbtng_migrator\Model\TableNameMap;

/**
 * Open connection and private adapter state for one isolated candidate. */
final class CandidateContext {

  private ?Connection $databaseConnection;

  /**
   * Creates per-run database and path state for the candidate.
   *
   * @param list<string> $publishedTableNames
   *   Existing canonical user tables that publication will rotate.
   */
  public function __construct(
    public StandbyCandidate $candidate,
    Connection $connection,
    public TableNameMap $tableNames,
    public array $publishedTableNames,
    public ?string $connectionKey = NULL,
    public ?string $partialDirectory = NULL,
    public ?string $candidatePath = NULL,
    public ?string $finalDirectory = NULL,
    public ?string $candidatePrefix = NULL,
    public ?string $archivePrefix = NULL,
    public ?string $previousPublishedIdentifier = NULL,
    public ?string $previousGenerationId = NULL,
    public string $manifestIdentity = '',
  ) {
    $this->databaseConnection = $connection;
  }

  public function connection(): Connection {
    return $this->databaseConnection ?? throw new \LogicException('Candidate connection has already been released.');
  }

  public function releaseConnection(): void {
    if ($this->connectionKey !== NULL) {
      Database::removeConnection($this->connectionKey);
    }
    $this->databaseConnection = NULL;
  }

}
