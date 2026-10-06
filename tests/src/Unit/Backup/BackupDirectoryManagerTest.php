<?php

declare(strict_types=1);

namespace Drupal\Tests\dbtng_migrator\Unit\Backup;

use Drupal\dbtng_migrator\Backup\BackupDirectoryManager;
use Drupal\dbtng_migrator\Exception\DbtngException;
use PHPUnit\Framework\TestCase;

/**
 * Tests private backup output directory rules. */
final class BackupDirectoryManagerTest extends TestCase {

  private string $root;

  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/dbtng-output-test-' . bin2hex(random_bytes(5));
    mkdir($this->root, 0700, TRUE);
  }

  protected function tearDown(): void {
    foreach (glob($this->root . '/*') ?: [] as $path) {
      if (is_dir($path)) {
        $this->removeDirectory($path);
      }
      elseif (is_file($path)) {
        unlink($path);
      }
    }
    rmdir($this->root);
  }

  private function removeDirectory(string $directory): void {
    foreach (glob($directory . '/*') ?: [] as $path) {
      if (is_dir($path)) {
        $this->removeDirectory($path);
      }
      elseif (is_file($path)) {
        unlink($path);
      }
    }
    rmdir($directory);
  }

  public function testCreatesPrivateOutputDirectory(): void {
    $requested = $this->root . '/new/nested';

    $prepared = (new BackupDirectoryManager())->prepare($requested);

    self::assertSame($requested, $prepared);
    self::assertSame(0700, fileperms($prepared) & 0777);
  }

  public function testRejectsSharedDirectoryWithoutChangingItsPermissions(): void {
    $shared = $this->root . '/shared';
    mkdir($shared, 0755);

    try {
      (new BackupDirectoryManager())->prepare($shared);
      self::fail('Shared output directory should be rejected.');
    }
    catch (DbtngException $exception) {
      self::assertStringContainsString('must be private', $exception->getMessage());
    }
    self::assertSame(0755, fileperms($shared) & 0777);
  }

}
