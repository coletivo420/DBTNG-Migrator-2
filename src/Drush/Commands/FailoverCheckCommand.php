<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Drush\Commands;

use Drupal\dbtng_migrator\Contract\FailoverReadinessCheckerInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports whether the standby data plane is ready for controlled promotion.
 */
#[AsCommand(name: 'dbtng:failover:check', description: 'Read-only controlled-failover readiness check.')]
#[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
final class FailoverCheckCommand extends Command {

  use AutowireTrait;

  public function __construct(private readonly FailoverReadinessCheckerInterface $checker) {
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
      $report = $this->checker->check();
      if ($format === 'json') {
        $output->writeln(json_encode(
          $report->toArray(),
          JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
      }
      else {
        $output->writeln('<info>DBTNG Failover Readiness (read-only)</info>');
        $output->writeln('Primary: ' . $report->primaryEngine);
        $output->writeln('Standby: ' . $report->standbyEngine);
        $output->writeln('Profile: ' . $report->profile->value);
        $output->writeln('Capture healthy: ' . ($report->captureHealthy ? 'YES' : 'NO'));
        $output->writeln('Pending events: ' . $report->pendingEvents);
        $output->writeln('Reconciliation: ' . $report->reconciliationStatus->value);
        $output->writeln('Integrity: ' . ($report->integrityPass ? 'PASS' : 'FAIL'));
        $output->writeln('Manifest: ' . ($report->manifestPass ? 'PASS' : 'FAIL'));
        $output->writeln('Generation: ' . ($report->generationId ?? 'none'));
        foreach ($report->blockers as $blocker) {
          $output->writeln(sprintf(
            'BLOCKER [%s] %s',
            $blocker->code->value,
            $blocker->message,
          ));
        }
        $output->writeln('Result: ' . $report->status->value);
        $output->writeln('External write fence: REQUIRED AND NOT VERIFIED BY THIS COMMAND');
        $output->writeln('Continuous worker stop: REQUIRED BEFORE AUTHORITY SWITCH');
        $output->writeln('Automatic promotion: NO');
      }
      return $report->ready() ? Command::SUCCESS : Command::FAILURE;
    }
    catch (\Throwable $exception) {
      $output->writeln('<error>Failover readiness could not be evaluated.</error>');
      $output->writeln('<comment>' . $exception->getMessage() . '</comment>');
      return Command::FAILURE;
    }
  }

}
