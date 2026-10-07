<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Drush\Commands;

use Drupal\dbtng_migrator\Contract\SyncEngineInterface;
use Drupal\dbtng_migrator\Sync\ContinuousSyncWorker;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs one bounded sync batch or the supervised continuous worker.
 */
#[AsCommand(name: 'dbtng:sync', description: 'Apply captured changes to the standby once or continuously.')]
#[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
final class SyncCommand extends Command implements SignalableCommandInterface {

  use AutowireTrait;

  public function __construct(
    private readonly SyncEngineInterface $sync,
    private readonly ContinuousSyncWorker $worker,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addOption('once', NULL, InputOption::VALUE_NONE, 'Run one bounded batch explicitly.')
      ->addOption('watch', NULL, InputOption::VALUE_NONE, 'Run the continuous sync worker until signalled.')
      ->addOption('limit', NULL, InputOption::VALUE_REQUIRED, 'Maximum events per batch; defaults to 500 for one-shot and configured batch_events for watch.');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $once = (bool) $input->getOption('once');
    $watch = (bool) $input->getOption('watch');
    if ($once && $watch) {
      $output->writeln('<error>--once and --watch are mutually exclusive.</error>');
      return Command::INVALID;
    }

    $rawLimit = $input->getOption('limit');
    $limit = NULL;
    if ($rawLimit !== NULL) {
      $validated = filter_var($rawLimit, FILTER_VALIDATE_INT);
      if (!is_int($validated) || $validated < 1 || $validated > 5000) {
        $output->writeln('<error>--limit must be an integer from 1 to 5000.</error>');
        return Command::INVALID;
      }
      $limit = $validated;
    }

    try {
      if ($watch) {
        $output->writeln('<info>DBTNG continuous sync worker starting.</info>');
        $this->worker->run($limit);
        $output->writeln('<info>DBTNG continuous sync worker stopped.</info>');
        return Command::SUCCESS;
      }

      $result = $this->sync->syncOnce($limit ?? 500);
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

  /**
   * {@inheritdoc}
   *
   * @return list<int>
   *   Available graceful-stop signals.
   */
  public function getSubscribedSignals(): array {
    $signals = [];
    foreach (['SIGINT', 'SIGTERM'] as $constant) {
      if (defined($constant)) {
        $signals[] = (int) constant($constant);
      }
    }
    return $signals;
  }

  public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false {
    $this->worker->requestStop();
    return FALSE;
  }

}
