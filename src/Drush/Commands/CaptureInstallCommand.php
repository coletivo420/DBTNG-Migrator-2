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
 * Installs durable row-trigger capture on the selected primary.
 */
#[AsCommand(name: 'dbtng:capture:install', description: 'Install durable DBTNG change capture on the selected primary.')]
#[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
final class CaptureInstallCommand extends Command {

  use AutowireTrait;

  public function __construct(private readonly ChangeCaptureInterface $capture) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    try {
      $status = $this->capture->install();
      $output->writeln('<info>DBTNG durable change capture installed.</info>');
      $output->writeln('Engine: ' . $status->engine->label());
      $output->writeln('Tracked tables: ' . $status->trackedTables);
      $output->writeln(sprintf('Triggers: %d/%d', $status->installedTriggers, $status->expectedTriggers));
      $output->writeln('Table-dirty fallback tables: ' . $status->tableDirtyTables);
      $output->writeln('Pending events: ' . $status->pendingEvents);
      $output->writeln('Health: ' . ($status->healthy ? 'PASS' : 'FAIL'));
      $output->writeln('<comment>Capture installation is not a synchronization baseline. Build/rebuild a validated standby after activation before claiming parity.</comment>');
      return $status->healthy ? Command::SUCCESS : Command::FAILURE;
    }
    catch (\Throwable $exception) {
      $output->writeln('<error>Change-capture installation failed.</error>');
      $output->writeln('<comment>' . $exception->getMessage() . '</comment>');
      return Command::FAILURE;
    }
  }

}
