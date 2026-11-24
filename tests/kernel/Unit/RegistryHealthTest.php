<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Unit;

use PHPUnit\Framework\TestCase;
use WPMCP\Kernel\Registry_Health;

/**
 * A development checkout without build/registry boots with no modules, so
 * their integrations and opt-in switches vanish without a word. The kernel
 * says so instead: one error_log line and an admin notice. A release copy
 * (no bin/build-registry.php) is never reported.
 */
final class RegistryHealthTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wpmcp-registry-health-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/bin', 0777, true);
        file_put_contents($this->root . '/bin/build-registry.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/build/registry/boot.php');
        @rmdir($this->root . '/build/registry');
        @rmdir($this->root . '/build');
        @unlink($this->root . '/bin/build-registry.php');
        @rmdir($this->root . '/bin');
        @rmdir($this->root);
    }

    public function test_a_dev_checkout_without_the_registry_is_reported(): void
    {
        $health = new Registry_Health($this->root);

        self::assertNotNull($health->problem());
        self::assertStringContainsString('build/registry', (string) $health->problem());
    }

    public function test_a_built_registry_is_not_reported(): void
    {
        mkdir($this->root . '/build/registry', 0777, true);
        file_put_contents($this->root . '/build/registry/boot.php', "<?php return [];\n");

        self::assertNull((new Registry_Health($this->root))->problem());
    }

    public function test_a_release_copy_is_not_reported(): void
    {
        unlink($this->root . '/bin/build-registry.php');

        self::assertNull((new Registry_Health($this->root))->problem());
    }

    public function test_report_logs_once_and_adds_the_admin_notice(): void
    {
        $logged = [];
        $hooks  = [];
        $health = new Registry_Health(
            $this->root,
            static function (string $line) use (&$logged): void {
                $logged[] = $line;
            },
            static function (string $hook, callable $callback) use (&$hooks): void {
                $hooks[] = $hook;
            },
        );

        self::assertTrue($health->report());
        self::assertTrue($health->report());
        self::assertCount(1, $logged);
        self::assertStringStartsWith('WPMCP: ', $logged[0]);
        self::assertSame(['admin_notices'], $hooks);
    }

    public function test_report_does_nothing_when_the_registry_is_there(): void
    {
        mkdir($this->root . '/build/registry', 0777, true);
        file_put_contents($this->root . '/build/registry/boot.php', "<?php return [];\n");
        $calls  = 0;
        $count  = static function () use (&$calls): void {
            ++$calls;
        };

        self::assertFalse((new Registry_Health($this->root, $count, $count))->report());
        self::assertSame(0, $calls);
    }
}
