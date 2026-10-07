<?php

declare(strict_types=1);

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

/**
 * Fails this validator with one concise message.
 */
function failValidation(string $message): never {
  fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
  exit(1);
}

/**
 * Recursively validates installed config keys against a mapping schema.
 *
 * @param array<string, mixed> $values
 *   Configuration values.
 * @param array<string, mixed> $schema
 *   Current schema node.
 */
function validateConfigMapping(array $values, array $schema, string $path): void {
  $mapping = $schema['mapping'] ?? NULL;
  if (!is_array($mapping)) {
    failValidation(sprintf('Schema node "%s" has no mapping.', $path));
  }

  foreach ($values as $key => $value) {
    if (!is_string($key) || !array_key_exists($key, $mapping) || !is_array($mapping[$key])) {
      failValidation(sprintf('Installed config key "%s.%s" is missing from config schema.', $path, (string) $key));
    }
    $child = $mapping[$key];
    if (is_array($value) && ($child['type'] ?? NULL) === 'mapping') {
      validateConfigMapping($value, $child, $path . '.' . $key);
    }
  }
}

$serviceDocument = Yaml::parseFile($root . '/dbtng_migrator.services.yml');
if (!is_array($serviceDocument) || !isset($serviceDocument['services']) || !is_array($serviceDocument['services'])) {
  failValidation('dbtng_migrator.services.yml does not contain a services mapping.');
}
$services = $serviceDocument['services'];

foreach ($services as $serviceId => $definition) {
  if (!is_string($serviceId) || !is_array($definition)) {
    failValidation('Every service definition must be a keyed mapping.');
  }

  $class = $definition['class'] ?? NULL;
  if (is_string($class) && str_starts_with($class, 'Drupal\\dbtng_migrator\\') && !class_exists($class)) {
    failValidation(sprintf('Internal service "%s" references missing class "%s".', $serviceId, $class));
  }

  $alias = $definition['alias'] ?? NULL;
  if (is_string($alias)
    && (str_starts_with($alias, 'dbtng_migrator.') || str_starts_with($alias, 'Drupal\\dbtng_migrator\\'))
    && !array_key_exists($alias, $services)) {
    failValidation(sprintf('Internal alias "%s" targets missing service "%s".', $serviceId, $alias));
  }

  $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($definition));
  foreach ($iterator as $value) {
    if (!is_string($value) || !str_starts_with($value, '@')) {
      continue;
    }
    $reference = ltrim($value, '@?');
    if ((str_starts_with($reference, 'dbtng_migrator.') || str_starts_with($reference, 'Drupal\\dbtng_migrator\\'))
      && !array_key_exists($reference, $services)) {
      failValidation(sprintf('Service "%s" references missing internal service "%s".', $serviceId, $reference));
    }
  }
}

$installed = Yaml::parseFile($root . '/config/install/dbtng_migrator.settings.yml');
$schemaDocument = Yaml::parseFile($root . '/config/schema/dbtng_migrator.schema.yml');
if (!is_array($installed)
  || !is_array($schemaDocument)
  || !isset($schemaDocument['dbtng_migrator.settings'])
  || !is_array($schemaDocument['dbtng_migrator.settings'])) {
  failValidation('Unable to load DBTNG installed config/schema.');
}
validateConfigMapping($installed, $schemaDocument['dbtng_migrator.settings'], 'dbtng_migrator.settings');

$commandNames = [];
foreach (glob($root . '/src/Drush/Commands/*Command.php') ?: [] as $file) {
  $class = 'Drupal\\dbtng_migrator\\Drush\\Commands\\' . basename($file, '.php');
  if (!class_exists($class)) {
    failValidation(sprintf('Drush command class does not autoload: %s.', $class));
  }
  $reflection = new ReflectionClass($class);
  if ($reflection->isAbstract()) {
    continue;
  }
  $attributes = $reflection->getAttributes(AsCommand::class);
  if (count($attributes) !== 1) {
    failValidation(sprintf('Drush command "%s" must declare exactly one AsCommand attribute.', $class));
  }
  $attribute = $attributes[0]->newInstance();
  $name = $attribute->name;
  if (!is_string($name) || $name === '') {
    failValidation(sprintf('Drush command "%s" has no command name.', $class));
  }
  if (isset($commandNames[$name])) {
    failValidation(sprintf('Duplicate Drush command name "%s" in %s and %s.', $name, $commandNames[$name], $class));
  }
  $commandNames[$name] = $class;
}

foreach (['dbtng:sync', 'dbtng:sync:status', 'dbtng:doctor', 'dbtng:capture:status'] as $requiredCommand) {
  if (!isset($commandNames[$requiredCommand])) {
    failValidation(sprintf('Required Phase F command metadata missing: %s.', $requiredCommand));
  }
}

printf(
  "PASS: project structure (%d services, %d Drush commands, config/schema aligned)\n",
  count($services),
  count($commandNames),
);
