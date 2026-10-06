<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Drush\Commands;

use Drupal\dbtng_migrator\Backup\NativeBackupManager;
use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Exception\DbtngException;
use Drupal\dbtng_migrator\Model\BackupCompression;
use Drupal\dbtng_migrator\Model\DatabaseRole;
use Drupal\dbtng_migrator\Model\NativeBackupRequest;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates a private native backup of either resolved database role. */
#[AsCommand(name: 'dbtng:backup', description: 'Create a native database backup of primary or standby.')]
final class BackupCommand extends Command {

  use AutowireTrait;

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly NativeBackupManager $backupManager,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addOption('role', NULL, InputOption::VALUE_REQUIRED, 'Database role to back up: primary or standby.', 'primary')
      ->addOption('compression', NULL, InputOption::VALUE_REQUIRED, 'Artifact compression: gzip or none.', 'gzip')
      ->addOption('output', NULL, InputOption::VALUE_REQUIRED, 'Private output directory.');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $role = DatabaseRole::tryFrom((string) $input->getOption('role'));
    $compression = BackupCompression::tryFrom((string) $input->getOption('compression'));
    if ($role === NULL || $compression === NULL) {
      $output->writeln('<error>Use --role=primary|standby and --compression=gzip|none.</error>');
      return Command::INVALID;
    }
    try {
      $topology = $this->resolver->resolve();
      $artifact = $this->backupManager->create(
        $topology->topology,
        new NativeBackupRequest($role, $compression, $input->getOption('output') === NULL ? NULL : (string) $input->getOption('output')),
      );
    }
    catch (DbtngException $exception) {
      $output->writeln(sprintf('<error>Backup failed: %s</error>', $exception->getMessage()));
      return Command::FAILURE;
    }
    catch (\Throwable) {
      $output->writeln('<error>Backup failed; no database credentials or connection details were emitted.</error>');
      return Command::FAILURE;
    }

    $output->writeln('<info>Backup created</info>');
    $output->writeln(sprintf('Role: %s', $artifact->role->value));
    $output->writeln(sprintf('Engine: %s', $artifact->engine->label()));
    $output->writeln(sprintf('Format: %s', $artifact->format->value));
    $output->writeln(sprintf('File: %s', $artifact->path));
    $output->writeln(sprintf('Bytes: %d', $artifact->bytes));
    $output->writeln(sprintf('SHA-256: %s', $artifact->sha256));
    return Command::SUCCESS;
  }

}
