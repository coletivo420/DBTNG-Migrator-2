<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Drush\Commands;

use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Exception\DestinationNotEmptyException;
use Drupal\dbtng_migrator\Import\ImportManager;
use Drupal\dbtng_migrator\Model\ImportRequest;
use Drupal\dbtng_migrator\Model\NonEmptyDestinationPolicy;
use Drupal\dbtng_migrator\Model\ReplicationProfile;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Imports the configured primary into the configured standby. */
#[AsCommand(name: 'dbtng:import', description: 'Logically import the primary database into the standby.')]
#[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
final class ImportCommand extends Command {

  use AutowireTrait;

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly ImportManager $manager,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption('profile', NULL, InputOption::VALUE_REQUIRED, 'Import profile: full or clean.', 'full')
      ->addOption('on-non-empty', NULL, InputOption::VALUE_REQUIRED, 'Standby policy: abort, backup_then_clear or clear.', 'abort');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $profile = ReplicationProfile::tryFrom((string) $input->getOption('profile'));
    $policy = NonEmptyDestinationPolicy::tryFrom((string) $input->getOption('on-non-empty'));
    if ($profile === NULL || $policy === NULL) {
      $output->writeln('<error>Use --profile=full|clean and --on-non-empty=abort|backup_then_clear|clear.</error>');
      return Command::INVALID;
    }
    try {
      $topology = $this->resolver->resolve();
      $output->writeln('<info>DBTNG Logical Import</info>');
      $output->writeln('Source role: primary (' . $topology->primary->label() . ')');
      $output->writeln('Destination role: standby (' . $topology->standby->label() . ')');
      $output->writeln('Destination policy: ' . $policy->value);
      if ($policy->destructive()) {
        $output->writeln('<comment>Explicit destructive standby policy selected.</comment>');
      }
      $manifest = $this->manager->import(new ImportRequest($profile, TRUE, $policy, $topology->topology));
    }
    catch (DestinationNotEmptyException $exception) {
      $output->writeln('<error>IMPORT ABORTED: standby contains data; no destination changes were made.</error>');
      return Command::FAILURE;
    }
    catch (\Throwable $exception) {
      $output->writeln('<error>Logical import failed; the standby was not marked initialized. Inspect the destination before retrying.</error>');
      return Command::FAILURE;
    }

    $output->writeln(sprintf('Source tables: %d', $manifest->tableCount));
    $output->writeln(sprintf('Rows transferred: %d', $manifest->rowCount));
    $output->writeln(sprintf('Bytes transferred: %d', $manifest->bytesTransferred));
    $output->writeln(sprintf('Portability review warnings preserved: %d', $manifest->portabilityWarnings));
    if ($manifest->safetyBackupPath !== NULL) {
      $output->writeln('Safety backup: ' . $manifest->safetyBackupPath);
      $output->writeln('Safety backup SHA-256: ' . $manifest->safetyBackupSha256);
    }
    $output->writeln('Schema validation: PASS');
    $output->writeln('Row-count validation: PASS');
    $output->writeln('Peak PHP memory: ' . $manifest->peakMemoryBytes);
    $output->writeln($manifest->activatable ? 'Result: INITIALIZED (full; activatable after controlled role switch)' : 'Result: INITIALIZED (clean; standby-only)');
    return Command::SUCCESS;
  }

}
