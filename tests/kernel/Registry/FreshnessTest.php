<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry;

use PHPUnit\Framework\TestCase;
use WPMCP\Registry\Freshness;

/**
 * A checkout's build/registry must never be missing or older than the module
 * sources it is compiled from: the test bootstraps and bin/test-local.sh
 * rebuild it through Freshness, so a worktree whose vendor/ was copied (no
 * composer post-autoload-dump) cannot run the suite on no registry.
 */
final class FreshnessTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wpmcp-freshness-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Modules/Acme', 0777, true);
        mkdir($this->root . '/bin', 0777, true);
        file_put_contents($this->root . '/src/Modules/Acme/Module.php', "<?php\n");
        file_put_contents($this->root . '/bin/build-registry.php', "<?php\n");
        touch($this->root . '/src/Modules/Acme/Module.php', 1_000_000);
        touch($this->root . '/bin/build-registry.php', 1_000_000);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function build(int $mtime): void
    {
        @mkdir($this->root . '/build/registry', 0777, true);
        file_put_contents($this->root . '/build/registry/boot.php', "<?php return [];\n");
        touch($this->root . '/build/registry/boot.php', $mtime);
    }

    public function test_a_missing_registry_is_stale(): void
    {
        self::assertTrue(Freshness::stale($this->root));
    }

    public function test_a_registry_newer_than_every_input_is_fresh(): void
    {
        $this->build(2_000_000);

        self::assertFalse(Freshness::stale($this->root));
    }

    public function test_a_module_edited_after_the_build_makes_it_stale(): void
    {
        $this->build(2_000_000);
        touch($this->root . '/src/Modules/Acme/Module.php', 3_000_000);

        self::assertTrue(Freshness::stale($this->root));
    }

    public function test_a_newer_generator_makes_it_stale(): void
    {
        $this->build(2_000_000);
        touch($this->root . '/bin/build-registry.php', 3_000_000);

        self::assertTrue(Freshness::stale($this->root));
    }

    public function test_a_release_copy_without_the_generator_is_never_stale(): void
    {
        unlink($this->root . '/bin/build-registry.php');

        self::assertFalse(Freshness::stale($this->root));
    }
}
