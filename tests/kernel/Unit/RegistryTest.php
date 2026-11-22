<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Unit;

use PHPUnit\Framework\TestCase;
use WPMCP\Kernel\Registry;

final class RegistryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpmcp-registry-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/free/ops', 0777, true);
        mkdir($this->dir . '/pro/ops', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['free', 'pro'] as $root) {
            foreach (glob($this->dir . "/{$root}/ops/*.php") ?: [] as $f) {
                unlink($f);
            }
            foreach (glob($this->dir . "/{$root}/*.php") ?: [] as $f) {
                unlink($f);
            }
            @rmdir($this->dir . "/{$root}/ops");
            @rmdir($this->dir . "/{$root}");
        }
        @rmdir($this->dir);
    }

    /** @param array<string, mixed> $data */
    private function write(string $root, string $file, array $data): void
    {
        file_put_contents($this->dir . "/{$root}/{$file}", '<?php return ' . var_export($data, true) . ';');
    }

    /** @return array<string, mixed> */
    private static function boot(string $id, string $namespace, array $extra = []): array
    {
        return $extra + [
            'format' => 1,
            'hash' => 'h-' . $id,
            'flavor' => 'full',
            'modules' => [$id => ['id' => $id, 'namespace' => $namespace, 'order' => 100, 'tier' => 'free', 'group' => $id, 'contexts' => [], 'requires' => null]],
            'hooks' => [['module' => $id, 'hook' => 'init', 'class' => $namespace . 'Hooks', 'method' => 'go', 'static' => true, 'priority' => 10, 'args' => 1, 'filter' => false, 'contexts' => [], 'kind' => 'hook']],
            'opt_in_gates' => [],
            'factories' => [$namespace . 'Tool' => []],
            'bindings' => [],
            'providers' => [],
            'ownership' => ['namespaces' => [$namespace => $id], 'paths' => []],
        ];
    }

    public function test_reads_several_roots_in_sequence_and_loads_abilities_lazily(): void
    {
        $this->write('free', 'boot.php', self::boot('redirects', 'WPMCP\\Modules\\Redirects\\'));
        $this->write('free', 'abilities.php', [['name' => 'wpmcp/list-redirects', 'module' => 'redirects']]);
        $this->write('free', 'ops/plugin-data.php', [['class' => 'A', 'order' => 1]]);
        $this->write('pro', 'boot.php', self::boot('seo-pro', 'WPMCP\\Modules\\Seo_Pro\\'));
        $this->write('pro', 'abilities.php', [['name' => 'wpmcp/seo-audit', 'module' => 'seo-pro']]);
        $this->write('pro', 'ops/plugin-data.php', [['class' => 'B', 'order' => 2]]);

        $registry = Registry::from_dirs([$this->dir . '/free', $this->dir . '/pro', $this->dir . '/missing']);

        self::assertSame(['redirects', 'seo-pro'], array_keys($registry->modules()));
        self::assertSame(['wpmcp/list-redirects', 'wpmcp/seo-audit'], array_column($registry->abilities(), 'name'));
        self::assertSame(['A', 'B'], array_column($registry->operations('plugin-data'), 'class'));
        self::assertSame([], $registry->operations('theme'));
        self::assertCount(2, $registry->hooks());
        self::assertSame('redirects', $registry->owner_of_class('WPMCP\\Modules\\Redirects\\Tools\\List_Redirects'));
        self::assertSame('seo-pro', $registry->owner_of_class('WPMCP\\Modules\\Seo_Pro\\Module'));
        self::assertNull($registry->owner_of_class('WPMCP\\Kernel\\Registry'));
        self::assertSame(['WPMCP\\Modules\\Redirects\\Tool' => [], 'WPMCP\\Modules\\Seo_Pro\\Tool' => []], $registry->factories());
        self::assertSame('h-redirects', $registry->hash());
        self::assertSame([], $registry->rejections());
    }

    public function test_a_later_root_that_reuses_a_module_id_is_rejected_not_merged(): void
    {
        $this->write('free', 'boot.php', self::boot('redirects', 'WPMCP\\Modules\\Redirects\\'));
        $this->write('free', 'abilities.php', [['name' => 'wpmcp/list-redirects', 'module' => 'redirects']]);
        $this->write('pro', 'boot.php', self::boot('redirects', 'Acme\\Redirects\\'));
        $this->write('pro', 'abilities.php', [['name' => 'acme/x', 'module' => 'redirects']]);

        $registry = Registry::from_dirs([$this->dir . '/free', $this->dir . '/pro']);

        self::assertSame('WPMCP\\Modules\\Redirects\\', $registry->module('redirects')['namespace']);
        self::assertSame(['wpmcp/list-redirects'], array_column($registry->abilities(), 'name'));
        self::assertCount(1, $registry->hooks());
        self::assertStringContainsString('redirects', $registry->rejections()[0]);
    }

    public function test_a_later_root_that_reuses_an_ability_name_loses_that_ability(): void
    {
        $this->write('free', 'boot.php', self::boot('a', 'A\\'));
        $this->write('free', 'abilities.php', [['name' => 'wpmcp/x', 'module' => 'a']]);
        $this->write('pro', 'boot.php', self::boot('b', 'B\\'));
        $this->write('pro', 'abilities.php', [['name' => 'wpmcp/x', 'module' => 'b'], ['name' => 'wpmcp/y', 'module' => 'b']]);

        $registry = Registry::from_dirs([$this->dir . '/free', $this->dir . '/pro']);

        self::assertSame([['name' => 'wpmcp/x', 'module' => 'a'], ['name' => 'wpmcp/y', 'module' => 'b']], $registry->abilities());
        self::assertNotEmpty($registry->rejections());
    }

    public function test_an_unsupported_format_is_rejected(): void
    {
        $this->write('free', 'boot.php', ['format' => 999] + self::boot('a', 'A\\'));

        $registry = Registry::from_dirs([$this->dir . '/free']);

        self::assertSame([], $registry->modules());
        self::assertStringContainsString('format', $registry->rejections()[0]);
    }

    public function test_from_arrays_is_a_test_seam(): void
    {
        $registry = Registry::from_arrays(self::boot('a', 'A\\'), [['name' => 'a/b', 'module' => 'a']]);

        self::assertSame(['a'], array_keys($registry->modules()));
        self::assertTrue($registry->has_module('a'));
        self::assertFalse($registry->has_module('b'));
        self::assertSame([['name' => 'a/b', 'module' => 'a']], $registry->abilities());
    }

    public function test_an_empty_registry_is_valid(): void
    {
        $registry = Registry::from_dirs([]);

        self::assertSame([], $registry->modules());
        self::assertSame([], $registry->abilities());
        self::assertSame('', $registry->hash());
    }
}
