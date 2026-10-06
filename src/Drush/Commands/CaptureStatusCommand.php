<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Drush\Commands;

use Drupal\dbtng_migrator\Contract\ChangeCaptureInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports primary-side durable capture health without changing databases.
 */
#[AsCommand(name: 'dbtng:capture:status', description: 'Report durable DBTNG capture health and backlog.')]
#[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
final class CaptureStatusCommand extends Command {

  use AutowireTrait;

  public function __construct(private readonly ChangeCaptureInterface $capture) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    try {
      $status = $this->capture->status();
      $output->writeln('<info>DBTNG Change Capture</info>');
      $output->writeln('Engine: ' . $status->engine->label());
      $output->writeln('Installed: ' . ($status->installed ? 'YES' : 'NO'));
      $output->writeln('Healthy: ' . ($status->healthy ? 'YES' : 'NO'));
      $output->writeln(sprintf('Triggers: %d/%d', $status->installedTriggers, $status->expectedTriggers));
      $output->writeln('Tracked tables: ' . $status->trackedTables);
      $output->writeln('Table-dirty fallback tables: ' . $status->tableDirtyTables);
      $output->writeln('Pending events: ' . $status->pendingEvents);
      $output->writeln('Oldest event ID: ' . ($status->oldestEventId ?? 'none'));
      $output->writeln('Newest event ID: ' . ($status->newestEventId ?? 'none'));
      foreach ($status->warnings as $warning) {
        $output->writeln('<comment>WARNING: ' . $warning . '</comment>');
      }
      return $status->healthy ? Command::SUCCESS : Command::FAILURE;
    }
    catch (\Throwable $exception) {
      $output->writeln('<error>Change-capture status failed.</error>');
      $output->writeln('<comment>' . $exception->getMessage() . '</comment>');
      return Command::FAILURE;
    }
  }

}
