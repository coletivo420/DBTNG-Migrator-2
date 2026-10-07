<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Drush\Commands;

use Drupal\dbtng_migrator\Model\SyncHealth;
use Drupal\dbtng_migrator\Sync\SyncMonitoringService;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports worker and capture health without calling systemd.
 */
#[AsCommand(name: 'dbtng:sync:status', description: 'Report continuous sync worker, capture and backlog health.')]
#[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
final class SyncStatusCommand extends Command {

  use AutowireTrait;

  public function __construct(private readonly SyncMonitoringService $monitor) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption('format', NULL, InputOption::VALUE_REQUIRED, 'Output format: text or json.', 'text');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $format = strtolower((string) $input->getOption('format'));
    if (!in_array($format, ['text', 'json'], TRUE)) {
      $output->writeln('<error>--format must be text or json.</error>');
      return Command::INVALID;
    }
    try {
      $report = $this->monitor->report();
      if ($format === 'json') {
        $output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
      }
      else {
        $output->writeln('<info>DBTNG Continuous Sync</info>');
        $output->writeln('Health: ' . $report->health->value);
        $output->writeln('Worker state: ' . ($report->workerState === NULL ? 'UNKNOWN' : $report->workerState->value));
        $output->writeln('Worker PID: ' . ($report->workerPid ?? 'unknown'));
        $output->writeln('Heartbeat age: ' . ($report->heartbeatAgeSeconds === NULL ? 'unknown' : $report->heartbeatAgeSeconds . 's'));
        $output->writeln('Primary: ' . $report->primaryEngine);
        $output->writeln('Standby: ' . $report->standbyEngine);
        $output->writeln('Profile: ' . $report->profile);
        $output->writeln('Capture healthy: ' . ($report->captureHealthy ? 'YES' : 'NO'));
        $output->writeln('Pending events: ' . $report->pendingEvents);
        $output->writeln('Oldest pending age: ' . ($report->oldestPendingAgeSeconds === NULL ? 'none' : $report->oldestPendingAgeSeconds . 's'));
        $output->writeln('Last success: ' . ($report->lastSuccessAt ?? 'none'));
        $output->writeln('Consecutive failures: ' . $report->consecutiveFailures);
        $output->writeln('Current backoff: ' . $report->currentBackoffSeconds . 's');
        if ($report->lastErrorClass !== NULL) {
          $output->writeln('Last error class: ' . $report->lastErrorClass);
        }
      }

      return in_array($report->health, [
        SyncHealth::Healthy,
        SyncHealth::CatchingUp,
        SyncHealth::Lagging,
      ], TRUE) ? Command::SUCCESS : Command::FAILURE;
    }
    catch (\Throwable $exception) {
      $output->writeln('<error>Unable to read DBTNG continuous sync status.</error>');
      $output->writeln('<comment>' . $exception->getMessage() . '</comment>');
      return Command::FAILURE;
    }
  }

}
