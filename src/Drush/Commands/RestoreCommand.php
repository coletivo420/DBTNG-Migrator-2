<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Drush\Commands;

use Drupal\dbtng_migrator\Backup\NativeRestoreManager;
use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drupal\dbtng_migrator\Model\NativeBackupFormat;
use Drupal\dbtng_migrator\Model\NativeRestoreRequest;
use Drupal\dbtng_migrator\Model\NonEmptyDestinationPolicy;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Restores a private same-engine artifact to the configured standby only. */
#[AsCommand(name: 'dbtng:restore', description: 'Restore a native backup into the configured standby.')]
#[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
final class RestoreCommand extends Command {

  use AutowireTrait;

  public function __construct(
    private readonly DatabaseTopologyResolver $resolver,
    private readonly NativeRestoreManager $manager,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addArgument('file', InputArgument::REQUIRED, 'Private .sql[.gz] or .sqlite[.gz] artifact path.')
      ->addOption('on-non-empty', NULL, InputOption::VALUE_REQUIRED, 'Standby policy: abort, backup_then_clear or clear.', 'abort');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $path = (string) $input->getArgument('file');
    $policy = NonEmptyDestinationPolicy::tryFrom((string) $input->getOption('on-non-empty'));
    $format = match (TRUE) {
      str_ends_with(strtolower($path), '.sql.gz') => NativeBackupFormat::MysqlSqlGzip,
      str_ends_with(strtolower($path), '.sql') => NativeBackupFormat::MysqlSql,
      str_ends_with(strtolower($path), '.sqlite.gz') => NativeBackupFormat::SqliteDatabaseGzip,
      str_ends_with(strtolower($path), '.sqlite') => NativeBackupFormat::SqliteDatabase,
      default => NULL,
    };
    if ($policy === NULL || $format === NULL) {
      $output->writeln('<error>Use a supported native artifact and --on-non-empty=abort|backup_then_clear|clear.</error>');
      return Command::INVALID;
    }
    try {
      $topology = $this->resolver->resolve();
      $result = $this->manager->restore($topology->topology, new NativeRestoreRequest($path, $format, $policy));
    }
    catch (\Throwable) {
      $output->writeln('<error>Native restore failed. The standby was not marked initialized; inspect it before retrying.</error>');
      return Command::FAILURE;
    }
    $output->writeln('<info>Native restore validated</info>');
    $output->writeln('Role: standby');
    $output->writeln('Engine: ' . $result->engine->label());
    $output->writeln('Format: ' . $result->format->value);
    $output->writeln('Destination policy: ' . $result->preparation->policy->value);
    if ($result->preparation->safetyBackup !== NULL) {
      $output->writeln('Safety backup: ' . $result->preparation->safetyBackup->path);
      $output->writeln('Safety backup SHA-256: ' . $result->preparation->safetyBackup->sha256);
    }
    $output->writeln('Validation: PASS');
    return Command::SUCCESS;
  }

}
