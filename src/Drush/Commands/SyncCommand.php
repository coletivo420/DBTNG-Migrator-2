<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Drush\Commands;

use Drupal\dbtng_migrator\Contract\SyncEngineInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs exactly one bounded synchronization batch. */
#[AsCommand(name: 'dbtng:sync', description: 'Apply one bounded batch of captured changes to the standby.')]
#[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
final class SyncCommand extends Command {

  use AutowireTrait;

  public function __construct(private readonly SyncEngineInterface $sync) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addOption('once', NULL, InputOption::VALUE_NONE, 'Run one bounded batch (the only supported mode).')
      ->addOption('limit', NULL, InputOption::VALUE_REQUIRED, 'Maximum events to read in this batch.', '500');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
    if (!is_int($limit) || $limit < 1 || $limit > 5000) {
      $output->writeln('<error>--limit must be an integer from 1 to 5000.</error>');
      return Command::INVALID;
    }
    try {
      $result = $this->sync->syncOnce($limit);
      $output->writeln('<info>DBTNG Sync (one bounded batch)</info>');
      $output->writeln('Primary: ' . $result->primaryEngine);
      $output->writeln('Standby: ' . $result->standbyEngine);
      $output->writeln('Profile: ' . $result->profile);
      $output->writeln('Events read: ' . $result->capturedEvents);
      $output->writeln('Dirty identities: ' . $result->dirtyIdentities);
      $output->writeln('Table-dirty identities: ' . $result->tableDirty);
      $output->writeln('Upserts: ' . $result->upserts);
      $output->writeln('Deletes: ' . $result->deletes);
      $output->writeln('Policy no-ops: ' . $result->policyNoops);
      $output->writeln('Table reconciliations: ' . $result->tableReconciliations);
      $output->writeln('Acknowledged exact IDs: ' . $result->acknowledgedEvents);
      $output->writeln('Pending after batch: ' . $result->pendingAfter);
      $output->writeln('Duration: ' . $result->durationMilliseconds . ' ms');
      $output->writeln('Peak memory delta: ' . $result->peakMemoryBytes . ' bytes');
      $output->writeln('Result: ' . $result->result->value);
      return Command::SUCCESS;
    }
    catch (\Throwable $exception) {
      $output->writeln('<error>DBTNG sync failed.</error>');
      $output->writeln('<comment>' . $exception->getMessage() . '</comment>');
      return Command::FAILURE;
    }
  }

}
