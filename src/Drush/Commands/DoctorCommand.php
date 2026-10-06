<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Drush\Commands;

use Drupal\dbtng_migrator\Model\DatabaseProduct;
use Drupal\dbtng_migrator\Connection\DatabaseTopologyResolver;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports resolved role connections and local backup prerequisites. */
#[AsCommand(name: 'dbtng:doctor', description: 'Check DBTNG database topology and backup tools.')]
final class DoctorCommand extends Command {

  use AutowireTrait;

  public function __construct(private readonly DatabaseTopologyResolver $resolver) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $output->writeln('<info>DBTNG Migrator 2</info>');
    try {
      $resolved = $this->resolver->resolve();
    }
    catch (\Throwable) {
      $output->writeln('<error>Topology: FAILED. Check role keys, expected engines and database connectivity.</error>');
      return Command::FAILURE;
    }

    foreach ([$resolved->primary, $resolved->standby] as $database) {
      $output->writeln('');
      $output->writeln(strtoupper($database->role->value));
      $output->writeln(sprintf('  connection: %s/default', $database->connectionKey));
      $output->writeln(sprintf('  engine: %s', $database->label()));
      $output->writeln(sprintf('  version: %s', $database->serverVersion));
      if ($database->databasePath !== NULL) {
        $output->writeln(sprintf('  path: %s', $database->databasePath));
      }
      $output->writeln('  status: OK');
    }
    $output->writeln('');
    $output->writeln('TOPOLOGY');
    $output->writeln(sprintf('  %s -> %s', $resolved->primary->label(), $resolved->standby->label()));
    $output->writeln(sprintf('  profile: %s', $resolved->profile->value));
    $output->writeln('  status: VALID');
    $output->writeln('');
    $output->writeln('TOOLS');
    $mysqlTool = $resolved->primary->engine->value === 'mysql' ? $resolved->primary : $resolved->standby;
    $dumpNames = $mysqlTool->product === DatabaseProduct::MariaDb ? ['mariadb-dump', 'mysqldump'] : ['mysqldump'];
    $dumpLabel = $mysqlTool->product === DatabaseProduct::MariaDb ? 'mariadb-dump' : 'mysqldump';
    $output->writeln(sprintf('  %s: %s', $dumpLabel, $this->findExecutable($dumpNames) ?? 'MISSING'));
    $output->writeln(sprintf('  sqlite3: %s', $this->findExecutable(['sqlite3']) ?? 'MISSING'));
    $output->writeln(sprintf('  gzip: %s', $this->findExecutable(['gzip']) ?? 'MISSING'));
    $output->writeln(sprintf('  SQLite3::backup(): %s', class_exists(\SQLite3::class) ? 'AVAILABLE' : 'UNAVAILABLE'));
    return Command::SUCCESS;
  }

  /**
   * Finds an installed executable using PATH.
   *
   * @param list<string> $names
   *   Candidate executable basenames.
   */
  private function findExecutable(array $names): ?string {
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
      foreach ($names as $name) {
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (is_file($path) && is_executable($path)) {
          return $path;
        }
      }
    }
    return NULL;
  }

}
