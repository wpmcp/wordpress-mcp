<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Support;

use PHPUnit\Framework\TestCase;
use WPMCP\Kernel\Container;
use WPMCP\Kernel\Flavor;
use WPMCP\Registry\Build_Options;
use WPMCP\Registry\Builder;
use WPMCP\Registry\Flavors;

/**
 * The fast conformance checks every module inherits with one short class
 * (module architecture, addendum section 11.2): the module's registry
 * entries compile and validate, every class-string resolves in a strict
 * container with the module's fakes bound, its surface.lock.php is fresh,
 * write ops are gated with a labelled opt-in, every id-like op argument is
 * declared in objects or not_objects, and the module ships in exactly the
 * flavors it declares. No WordPress is loaded.
 *
 * The integration checks (snapshot then rollback round trip, Requires off,
 * contained boot) need WordPress and arrive with the first migrated module.
 */
abstract class Module_Conformance_Test extends TestCase
{
    /** @var array<string, array{model: array<string, mixed>, dir: string}> */
    private static array $built = [];

    /** @return class-string the module's #[Module] class */
    abstract protected static function module(): string;

    /**
     * In-memory fakes for the interfaces the module's classes need, keyed by
     * interface. Override when the module's adapter is injected.
     *
     * @return array<class-string, object>
     */
    protected static function fakes(): array
    {
        return [];
    }

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/registry/wp-stubs.php';
    }

    /** @return array<string, mixed> */
    private static function build(string $flavor = 'full', bool $check = false): array
    {
        $dir = dirname((string) (new \ReflectionClass(static::module()))->getFileName());
        $out = sys_get_temp_dir() . '/wpmcp-conformance-' . getmypid() . '-' . md5($dir . $flavor);
        $result = (new Builder(new Build_Options(
            roots: [$dir],
            out: $out . '/registry',
            flavor: $flavor,
            repo_root: dirname(__DIR__, 3),
            check: $check,
            locks: false,
        )))->run();
        $data = ['errors' => $result->errors, 'dir' => $dir, 'out' => $out . '/registry'];
        if (! $check && $result->ok()) {
            $data['boot'] = require $out . '/registry/boot.php';
            $data['abilities'] = require $out . '/registry/abilities.php';
            $data['ops'] = [];
            foreach (glob($out . '/registry/ops/*.php') ?: [] as $file) {
                $data['ops'][ basename($file, '.php') ] = require $file;
            }
            self::cleanup($out);
        }
        return $data;
    }

    private static function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /** @return array<string, mixed> */
    private static function full(): array
    {
        return self::$built[ static::module() ] ??= self::build();
    }

    public function test_registration_compiles_and_validates(): void
    {
        $full = self::full();

        self::assertSame([], $full['errors'], implode("\n", $full['errors']));
        self::assertCount(1, $full['boot']['modules'], 'the module folder holds exactly one module');
    }

    public function test_every_class_string_resolves_in_a_strict_container(): void
    {
        $boot = self::full()['boot'];
        $container = new Container($boot['factories'], $boot['bindings'], strict: true);
        foreach (static::fakes() as $interface => $fake) {
            $container->set($interface, $fake);
        }
        foreach (array_keys($boot['factories']) as $class) {
            self::assertIsObject($container->get($class), $class);
        }
        foreach (array_merge(self::full()['abilities'], ...array_values(self::full()['ops'])) as $row) {
            self::assertTrue(class_exists($row['class']), $row['class'] . ' does not exist');
        }
    }

    public function test_surface_lock_is_fresh(): void
    {
        $check = self::build('full', true);

        self::assertSame([], $check['errors'], implode("\n", $check['errors']) . "\nRun composer registry:build and commit surface.lock.php.");
    }

    public function test_write_ops_are_gated_and_every_gate_is_labelled(): void
    {
        $gates = self::full()['boot']['opt_in_gates'];
        foreach (self::full()['ops'] as $rows) {
            foreach ($rows as $row) {
                foreach ($row['ops'] as $op => $meta) {
                    if ('read' === $meta['mode']) {
                        continue;
                    }
                    if (false === ($meta['enabled_by_default'] ?? true)) {
                        self::assertArrayHasKey('opt_in_filter', $meta, "{$op} is off by default but names no opt-in filter");
                        self::assertArrayHasKey($meta['opt_in_filter'], $gates, "{$op}'s opt-in filter has no #[Opt_In_Gate] (with a label)");
                    }
                }
            }
        }
        self::assertTrue(true);
    }

    public function test_id_like_op_args_are_declared_as_objects_or_not_objects(): void
    {
        foreach (self::full()['ops'] as $rows) {
            foreach ($rows as $row) {
                foreach ($row['ops'] as $op => $meta) {
                    foreach ($meta['id_args'] ?? [] as $arg) {
                        self::assertTrue(
                            in_array($arg, $meta['objects'] ?? [], true) || isset($row['not_objects'][ "{$op}:{$arg}" ]),
                            "{$op} takes {$arg}: declare it in the op's objects, or in not_objects as '{$op}:{$arg}' with the reason"
                        );
                    }
                }
            }
        }
        self::assertTrue(true);
    }

    public function test_ships_in_exactly_the_declared_flavors(): void
    {
        $module = array_values(self::full()['boot']['modules'])[0];
        foreach (Flavors::all() as $id => $flavor) {
            /** @var Flavor $flavor */
            $built = self::build($id);
            self::assertSame([], $built['errors'], implode("\n", $built['errors']));
            self::assertSame($flavor->includes_module($module), [] !== $built['boot']['modules'], "module {$module['id']} in flavor {$id}");
        }
    }
}
