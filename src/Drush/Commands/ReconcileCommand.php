<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Drush\Commands;

use Drupal\dbtng_migrator\Contract\ReconciliationEngineInterface;
use Drupal\dbtng_migrator\Model\ReconciliationStatus;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Exposes read-only reconciliation through Drush.
 */
#[AsCommand(name: 'dbtng:reconcile', description: 'Compare the primary and standby without changing either database.')]
#[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
final class ReconcileCommand extends Command {

  use AutowireTrait;

  public function __construct(private readonly ReconciliationEngineInterface $engine) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    try {
      $report = $this->engine->reconcile();
      $output->writeln('<info>DBTNG Reconciliation (read-only)</info>');
      $output->writeln('Primary: ' . $report->primaryEngine);
      $output->writeln('Standby: ' . $report->standbyEngine);
      $output->writeln('Profile: ' . $report->profile->value);
      $output->writeln(sprintf('Tables: %d compared', count($report->tables)));
      $output->writeln('Integrity: ' . ($report->integrityPass ? 'PASS' : 'FAIL'));
      $output->writeln('Manifest: ' . ($report->manifestPass ? 'PASS' : 'MISSING/INVALID'));
      foreach ($report->issues as $issue) {
        $output->writeln(sprintf('ISSUE [%s] %s%s', $issue->code, $issue->message, $issue->table === NULL ? '' : ' (' . $issue->table . ')'));
      }
      $output->writeln('Result: ' . $report->status->value);
      if ($report->status === ReconciliationStatus::Drift || $report->status === ReconciliationStatus::RebuildRequired) {
        $output->writeln('Recommendation: REBUILD_REQUIRED');
      }
      return $report->matches() ? Command::SUCCESS : Command::FAILURE;
    }
    catch (\Throwable $exception) {
      $output->writeln('<error>Reconciliation could not be completed.</error>');
      $output->writeln('<comment>' . $exception->getMessage() . '</comment>');
      return Command::FAILURE;
    }
  }

}
