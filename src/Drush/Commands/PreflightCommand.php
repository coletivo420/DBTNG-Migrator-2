<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Drush\Commands;

use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\PortabilityAnalyzerInterface;
use Drupal\dbtng_migrator\Destination\DestinationStateInspectionManager;
use Drupal\dbtng_migrator\Model\DatabaseProduct;
use Drupal\dbtng_migrator\Model\DestinationState;
use Drupal\dbtng_migrator\Schema\SchemaIntrospectionManager;
use Drupal\Core\Database\StatementInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Performs read-only topology, destination and strict portability checks. */
#[AsCommand(name: 'dbtng:preflight', description: 'Inspect topology, schema portability and standby readiness.')]
#[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
final class PreflightCommand extends Command {

  use AutowireTrait;

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly SchemaIntrospectionManager $introspector,
    private readonly DestinationStateInspectionManager $destinationInspector,
    private readonly PortabilityAnalyzerInterface $portabilityAnalyzer,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    try {
      $topology = $this->resolver->resolve();
      $primaryCheck = $topology->primary->connection->query('SELECT 1');
      $standbyCheck = $topology->standby->connection->query('SELECT 1');
      if (!$primaryCheck instanceof StatementInterface || !$standbyCheck instanceof StatementInterface) {
        throw new \RuntimeException('A configured role did not return a connection check statement.');
      }
      $primaryCheck->fetchField();
      $standbyCheck->fetchField();
      $source = $this->introspector->inspect($topology->primary->connection);
      $destinationInventory = $this->introspector->inspect($topology->standby->connection);
      $destination = $this->destinationInspector->inspect($topology->standby->connection);
      $report = $this->portabilityAnalyzer->analyze($source);
    }
    catch (\Throwable) {
      $output->writeln('<error>PREFLIGHT FAILED: topology, connection, or physical inventory could not be resolved.</error>');
      return Command::FAILURE;
    }

    $output->writeln(sprintf('Direction: %s -> %s', $topology->primary->label(), $topology->standby->label()));
    $output->writeln(sprintf('Profile: %s', $topology->profile->value));
    $output->writeln(sprintf('Source: %d tables; %d schema objects', $source->tableCount(), count($source->objects)));
    $output->writeln(sprintf('Destination: %s (%d tables inventoried)', strtoupper($destination->state->value), $destinationInventory->tableCount()));
    $affectedTables = [];
    foreach ($report->issues as $issue) {
      if ($issue->table !== NULL) {
        $affectedTables[$issue->table] = TRUE;
      }
    }
    $output->writeln(sprintf(
      'Portability: safe=%d warnings=%d errors=%d',
      max(0, $source->tableCount() - count($affectedTables)),
      $report->countBySeverity('warning'),
      $report->countBySeverity('error'),
    ));

    foreach ($destination->reasons as $reason) {
      $output->writeln(sprintf('<error>DESTINATION OBJECT: %s</error>', $reason));
    }
    foreach ($report->issues as $issue) {
      $location = implode('.', array_filter([$issue->table, $issue->column, $issue->objectName]));
      $output->writeln(sprintf('<%s>%s: %s%s</%s>', $issue->severity === 'error' ? 'error' : 'comment', strtoupper($issue->severity), $location === '' ? '' : $location . ' — ', $issue->message, $issue->severity === 'error' ? 'error' : 'comment'));
    }

    $toolsAvailable = $this->requiredToolsAvailable($topology->primary->product, $topology->standby->product);
    $ready = $report->isPortable() && $destination->state === DestinationState::Empty && $toolsAvailable;
    $output->writeln($ready ? '<info>RESULT: READY (preflight only; no data was changed)</info>' : '<error>RESULT: BLOCKED (preflight only; no data was changed)</error>');
    return $ready ? Command::SUCCESS : Command::FAILURE;
  }

  private function requiredToolsAvailable(DatabaseProduct $primary, DatabaseProduct $standby): bool {
    $products = [$primary, $standby];
    if (in_array(DatabaseProduct::Sqlite, $products, TRUE) && !class_exists(\SQLite3::class)) {
      return FALSE;
    }
    if (in_array(DatabaseProduct::MariaDb, $products, TRUE) && $this->findExecutable(['mariadb-dump', 'mysqldump']) === NULL) {
      return FALSE;
    }
    if (in_array(DatabaseProduct::Mysql, $products, TRUE) && $this->findExecutable(['mysqldump']) === NULL) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Finds an installed executable using PATH.
   *
   * @param list<string> $names
   *   Candidate executable basenames.
   */
  private function findExecutable(array $names): ?string {
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
      foreach ($names as $name) {
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (is_file($path) && is_executable($path)) {
          return $path;
        }
      }
    }
    return NULL;
  }

}
