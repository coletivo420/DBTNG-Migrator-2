<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Drush\Commands;

use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Contract\RebuildManagerInterface;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drupal\dbtng_migrator\Model\SnapshotRequest;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Exposes isolated standby rebuilds through Drush.
 */
#[AsCommand(name: 'dbtng:rebuild', description: 'Build, validate and publish an isolated standby candidate.')]
#[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
final class RebuildCommand extends Command {

  use AutowireTrait;

  public function __construct(private readonly DatabaseTopologyResolver $resolver, private readonly RebuildManagerInterface $manager) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption('profile', NULL, InputOption::VALUE_REQUIRED, 'Rebuild profile: full or clean.', 'full');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $profile = ReplicationProfile::tryFrom((string) $input->getOption('profile'));
    if ($profile === NULL) {
      $output->writeln('<error>Use --profile=full|clean.</error>');
      return Command::INVALID;
    }
    try {
      $topology = $this->resolver->resolve();
      $output->writeln('<info>DBTNG Standby Rebuild</info>');
      $output->writeln('Primary: ' . $topology->primary->label());
      $output->writeln('Standby: ' . $topology->standby->label());
      $output->writeln('Profile: ' . $profile->value);
      $result = $this->manager->rebuild(new SnapshotRequest($profile, TRUE, $topology->topology));
      $output->writeln('Rebuild UUID: ' . $result->candidateId);
      $output->writeln('Candidate: ' . $result->candidateIdentifier);
      $output->writeln('Tables: ' . $result->manifest->tableCount);
      $output->writeln('Rows: ' . $result->manifest->rowCount);
      $output->writeln('Batches: ' . $result->batchCount);
      $output->writeln('Peak memory: ' . $result->manifest->peakMemoryBytes . ' bytes');
      $output->writeln('Validation: PASS');
      $output->writeln('Reconciliation: ' . $result->reconciliationStatus);
      $output->writeln('Published standby: ' . $result->publishedIdentifier);
      $output->writeln('Previous generation retained: ' . ($result->previousPublishedIdentifier ?? 'none'));
      $output->writeln($result->manifest->activatable ? 'Result: PUBLISHED (full)' : 'Result: PUBLISHED (clean; standby-only)');
      return Command::SUCCESS;
    }
    catch (\Throwable $exception) {
      $output->writeln('<error>Rebuild failed. The last published standby remains available.</error>');
      $output->writeln('<comment>' . $exception->getMessage() . '</comment>');
      return Command::FAILURE;
    }
  }

}
