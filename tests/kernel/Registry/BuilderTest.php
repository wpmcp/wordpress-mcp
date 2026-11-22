<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry;

use PHPUnit\Framework\TestCase;
use WPMCP\Registry\Build_Options;
use WPMCP\Registry\Build_Result;
use WPMCP\Registry\Builder;
use WPMCP\Tests\Kernel\Registry\Fixture\Demo\Adapter\Demo_Adapter;
use WPMCP\Tests\Kernel\Registry\Fixture\Demo\Adapter\Wp_Demo_Adapter;
use WPMCP\Tests\Kernel\Registry\Fixture\Demo\Hooks\Things_Hooks;
use WPMCP\Tests\Kernel\Registry\Fixture\Demo\Ops\Things_Ops;
use WPMCP\Tests\Kernel\Registry\Fixture\Demo\Tools\List_Things;
use WPMCP\Tests\Kernel\Registry\Fixture\Demo\Tools\Update_Thing;
use WPMCP\Tests\Kernel\Registry\Fixture\Other\Tools\Ping;

/**
 * The registry generator (bin/build-registry.php) against fixture modules
 * under tests/kernel/Registry/Fixture, with no WordPress loaded.
 */
final class BuilderTest extends TestCase
{
    private string $out;

    public static function setUpBeforeClass(): void
    {
        // The generator runs build-time methods (opt-in labels call __())
        // under the same stubs bin/build-registry.php loads.
        require_once dirname(__DIR__, 3) . '/tools/registry/wp-stubs.php';
    }

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/wpmcp-registry-build-' . getmypid() . '-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        self::remove($this->out);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function build(array $overrides = []): Build_Result
    {
        $options = new Build_Options(...($overrides + [
            'roots' => [__DIR__ . '/Fixture'],
            'out' => $this->out . '/registry',
            'flavor' => 'full',
            'artifacts' => $this->out . '/artifacts',
            'locks_root' => $this->out . '/locks',
            'repo_root' => dirname(__DIR__, 3),
        ]));

        return (new Builder($options))->run();
    }

    /** @return mixed */
    private function read(string $file)
    {
        return require $this->out . '/registry/' . $file;
    }

    public function test_builds_the_fixture_modules_without_errors(): void
    {
        $result = $this->build();

        self::assertSame([], $result->errors, implode("\n", $result->errors));
        self::assertTrue($result->ok());
        self::assertSame(['other', 'demo'], array_keys($this->read('boot.php')['modules']), 'modules are ordered by order');
    }

    public function test_boot_carries_module_metadata_and_ownership(): void
    {
        $this->build();
        $boot = $this->read('boot.php');
        $demo = $boot['modules']['demo'];

        self::assertSame(1, $boot['format']);
        self::assertSame('full', $boot['flavor']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $boot['hash']);
        self::assertSame('demo', $demo['group']);
        self::assertSame(200, $demo['order']);
        self::assertSame('free', $demo['tier']);
        self::assertSame(['woocommerce'], $demo['flavors']);
        self::assertSame(['plugin' => ['demo/demo.php'], 'name' => 'Demo Plugin', 'slug' => 'demo'], $demo['requires']);
        self::assertSame('WPMCP\\Tests\\Kernel\\Registry\\Fixture\\Demo\\', $demo['namespace']);
        self::assertSame(['Demo things: list and update them safely'], $demo['features']);
        self::assertSame('demo', $boot['ownership']['namespaces']['WPMCP\\Tests\\Kernel\\Registry\\Fixture\\Demo\\']);
    }

    public function test_abilities_follow_module_order_then_position_then_name(): void
    {
        $this->build();

        self::assertSame(
            ['wpmcp/aaa-unpositioned', 'wpmcp/ping', 'wpmcp/update-thing', 'wpmcp/list-things', 'wpmcp/delete-thing'],
            array_column($this->read('abilities.php'), 'name')
        );
    }

    public function test_ability_descriptors_run_factories_and_resolve_tiers(): void
    {
        $this->build();
        $by_name = array_column($this->read('abilities.php'), null, 'name');
        $update = $by_name['wpmcp/update-thing'];

        self::assertSame('Update a demo thing (1 fields)', $update['description']);
        self::assertSame(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']], $update['input']);
        self::assertSame('pro', $update['tier']);
        self::assertSame('update', $update['operation']);
        self::assertTrue($update['destructive']);
        self::assertNull($update['read_only']);
        self::assertSame('objects', $update['objects']);
        self::assertSame(Update_Thing::class, $update['class']);
        self::assertSame('handle', $update['method']);
        self::assertSame('delete', $by_name['wpmcp/delete-thing']['method']);
        self::assertSame('free', $by_name['wpmcp/delete-thing']['tier'], 'an ability without a tier takes its module tier');
        self::assertSame(List_Things::INPUT, $by_name['wpmcp/list-things']['input']);
        self::assertSame(Ping::class, $by_name['wpmcp/ping']['class']);
    }

    public function test_the_factory_map_and_bindings_come_from_constructors_and_provides(): void
    {
        $this->build();
        $boot = $this->read('boot.php');

        self::assertSame([Demo_Adapter::class], $boot['factories'][ List_Things::class ]);
        self::assertSame([Demo_Adapter::class], $boot['factories'][ Things_Hooks::class ]);
        self::assertSame([], $boot['factories'][ Wp_Demo_Adapter::class ]);
        self::assertSame([], $boot['factories'][ Update_Thing::class ]);
        self::assertSame(Wp_Demo_Adapter::class, $boot['bindings'][ Demo_Adapter::class ]);
        self::assertSame(Wp_Demo_Adapter::class, $boot['providers'][ Demo_Adapter::class ][0]['class']);
    }

    public function test_hooks_cron_and_rollback_refreshers_are_compiled(): void
    {
        $this->build();
        $rows = array_map(
            static fn (array $h): string => sprintf('%s %s %s::%s p%d a%d %s%s', $h['kind'], $h['hook'], substr(strrchr($h['class'], '\\') ?: '', 1), $h['method'], $h['priority'], $h['args'], $h['static'] ? 'static' : 'instance', [] === $h['contexts'] ? '' : ' ' . implode(',', $h['contexts'])),
            $this->read('boot.php')['hooks']
        );

        self::assertSame([
            'hook template_redirect Things_Hooks::maybe_redirect p1 a1 instance frontend',
            'hook the_content Things_Hooks::filter_content p10 a1 static',
            'cron wpmcp_demo_cron Things_Hooks::run_cron p10 a1 instance',
            'rollback wpmcp_rollback_options_restored Things_Hooks::after_restore p10 a2 static',
            'rollback wpmcp_rollback_post_restored Things_Hooks::after_restore p10 a2 static',
        ], $rows);
        self::assertTrue($this->read('boot.php')['hooks'][1]['filter']);
    }

    public function test_operation_contributors_keep_scalar_metadata_only(): void
    {
        $this->build();
        $ops = $this->read('ops/plugin-data.php');

        self::assertCount(2, $ops);
        self::assertSame(Things_Ops::class, $ops[0]['class']);
        self::assertSame('operations', $ops[0]['method']);
        self::assertTrue($ops[0]['static']);
        self::assertSame('Demo things', $ops[0]['summary']);
        self::assertSame(1200, $ops[0]['summary_order']);
        self::assertSame(['plugin' => ['demo/demo.php']], $ops[0]['requires']);
        self::assertSame(['demo-get:row_id' => 'a row in its own table'], $ops[0]['not_objects']);
        self::assertSame(['mode' => 'read', 'objects' => ['post_id'], 'id_args' => ['post_id', 'row_id']], $ops[0]['ops']['demo-get']);
        self::assertSame(['mode' => 'destructive', 'capability' => 'manage_options', 'opt_in_filter' => 'wpmcp_enable_demo_write', 'enabled_by_default' => false], $ops[0]['ops']['demo-delete']);
        self::assertSame('extra_operations', $ops[1]['method']);
        self::assertNull($ops[1]['summary']);
        self::assertSame(['demo-extra' => 'Demo Extra Plugin'], $ops[1]['hosts']);
        self::assertSame(['mode' => 'write', 'tier' => 'pro'], $ops[1]['ops']['demo-extra']);
        self::assertStringNotContainsString('Closure', (string) file_get_contents($this->out . '/registry/ops/plugin-data.php'));
    }

    public function test_opt_in_gates_and_data_files(): void
    {
        $this->build();
        $boot = $this->read('boot.php');

        self::assertSame(['module' => 'demo', 'class' => Things_Ops::class, 'destructive' => true, 'labels' => 'opt_in_labels', 'group' => null], $boot['opt_in_gates']['wpmcp_enable_demo_write']);
        self::assertSame(['options' => ['wpmcp_demo'], 'tables' => ['wpmcp_demo_rows'], 'cron' => ['wpmcp_demo_cron'], 'post_types' => [], 'user_meta' => []], $this->read('data.php')['demo']);
        self::assertSame(['plugin' => ['demo/demo.php'], 'slug' => 'demo', 'url' => null, 'several' => false], $this->read('refusal-plugins.php')['Demo Plugin']);
        $skills = $this->read('skills.php');
        self::assertSame('demo', $skills['demo-skill']['module']);
        self::assertStringEndsWith('Fixture/Demo/skills/demo-skill/SKILL.md', $skills['demo-skill']['path']);
    }

    public function test_the_woocommerce_flavor_ships_only_modules_that_name_it(): void
    {
        $this->build(['flavor' => 'woocommerce']);

        self::assertSame(['demo'], array_keys($this->read('boot.php')['modules']));
        self::assertNotContains('wpmcp/ping', array_column($this->read('abilities.php'), 'name'));
    }

    public function test_the_wporg_flavor_drops_excluded_abilities(): void
    {
        $this->build(['flavor' => 'wporg']);
        $names = array_column($this->read('abilities.php'), 'name');

        self::assertNotContains('wpmcp/update-thing', $names);
        self::assertContains('wpmcp/delete-thing', $names);
        self::assertSame('wporg', $this->read('boot.php')['flavor']);
    }

    public function test_writes_one_surface_lock_per_module_and_check_detects_drift(): void
    {
        $this->build();
        $lock_file = $this->out . '/locks/demo/surface.lock.php';
        $lock = require $lock_file;

        self::assertStringStartsWith("<?php\n// GENERATED by composer registry:build", (string) file_get_contents($lock_file));
        self::assertSame('demo', $lock['module']);
        self::assertSame(['wpmcp/update-thing', 'wpmcp/list-things', 'wpmcp/delete-thing'], array_column($lock['abilities'], 'name'));
        self::assertSame(['demo-get', 'demo-delete', 'demo-extra'], $lock['operations']['plugin-data']);
        self::assertSame(['wpmcp_enable_demo_write'], $lock['opt_in_gates']);
        self::assertGreaterThan(0, $lock['tools_list_bytes']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $lock['abilities'][0]['input_hash']);

        self::assertSame([], $this->build(['check' => true])->errors, 'a fresh lock passes check');

        file_put_contents($lock_file, str_replace("'wpmcp/list-things'", "'wpmcp/renamed'", (string) file_get_contents($lock_file)));
        $errors = $this->build(['check' => true])->errors;
        self::assertCount(1, $errors);
        self::assertStringContainsString('surface.lock.php for module demo is stale', $errors[0]);
    }

    public function test_check_mode_writes_no_aggregates(): void
    {
        $this->build(['check' => true]);

        self::assertFileDoesNotExist($this->out . '/registry/boot.php');
    }

    public function test_writes_the_repo_artifacts(): void
    {
        $this->build();
        $counts = json_decode((string) file_get_contents($this->out . '/artifacts/counts.json'), true);

        self::assertSame(['total' => 5, 'free' => 4, 'pro' => 1, 'modules' => 2, 'operations' => 3], $counts['full']);
        self::assertSame("demo/demo.php\n", file_get_contents($this->out . '/artifacts/test-plugins.txt'));
        $catalog = json_decode((string) file_get_contents($this->out . '/artifacts/catalog.json'), true);
        self::assertSame('demo', $catalog['abilities'][2]['module']);
        $manifest = require $this->out . '/artifacts/ability-manifest.php';
        self::assertSame('pro', $manifest['wpmcp/update-thing']);
    }

    public function test_an_empty_root_builds_an_empty_registry(): void
    {
        $empty = $this->out . '/empty';
        mkdir($empty, 0777, true);
        $result = $this->build(['roots' => [$empty]]);

        self::assertTrue($result->ok());
        self::assertSame([], $this->read('boot.php')['modules']);
        self::assertSame([], $this->read('abilities.php'));
    }

    public function test_auto_mode_skips_when_no_module_is_found(): void
    {
        $empty = $this->out . '/empty';
        mkdir($empty, 0777, true);
        $result = $this->build(['roots' => [$empty], 'auto' => true]);

        self::assertTrue($result->ok());
        self::assertTrue($result->skipped);
        self::assertFileDoesNotExist($this->out . '/registry/boot.php');
    }

    public function test_output_is_deterministic(): void
    {
        $this->build();
        $first = (string) file_get_contents($this->out . '/registry/boot.php') . file_get_contents($this->out . '/registry/abilities.php');
        $this->build();

        self::assertSame($first, (string) file_get_contents($this->out . '/registry/boot.php') . file_get_contents($this->out . '/registry/abilities.php'));
    }

    /** @return iterable<string, array{list<string>, string, array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'duplicate ability' => [['Duplicate_Ability'], 'Ability name wpmcp/same is declared twice', []];
        yield 'duplicate module' => [['Duplicate_Module', 'Duplicate_Module_B'], 'Module id twice is declared twice', []];
        yield 'duplicate gate' => [['Duplicate_Gate'], 'Opt-in filter wpmcp_same_gate is declared twice', []];
        yield 'duplicate op' => [['Duplicate_Op'], 'Operation same-op on dispatcher plugin-data is contributed twice', []];
        yield 'unowned class' => [['Unowned'], 'belongs to no module', []];
        yield 'bad flavor' => [['Bad_Flavor'], 'unknown flavor nosuchflavor', []];
        yield 'bad schema' => [['Bad_Schema'], 'requires b, which is not in properties', []];
        yield 'long description' => [['Long_Description'], 'description is 301 bytes, over the 300 byte cap', []];
        yield 'missing label' => [['Missing_Label'], 'Opt-in filter wpmcp_unlabelled has no label', []];
        yield 'foreign parent' => [['Foreign_Parent'], 'extends ArrayObject', []];
        yield 'unknown dispatcher' => [['Unknown_Dispatcher'], 'unknown dispatcher no-such-pair', []];
        yield 'bad tier' => [['Bad_Tier'], 'Unknown tier "gold"', []];
        yield 'missing handler method' => [['Missing_Method'], 'has no method handle', []];
        yield 'refusal facts' => [['Refusal_Facts'], 'names X Plugin but neither a slug nor a url', []];
        yield 'bad name' => [['Bad_Name'], 'Ability name "Not A Name" is not vendor/name', []];
        yield 'reserved prefix for add-ons' => [['Duplicate_Op'], 'reserved', ['addon' => true, 'roots' => [__DIR__ . '/Fixture/Other']]];
    }

    /**
     * @dataProvider invalid
     * @param list<string>          $dirs
     * @param array<string, mixed>  $extra
     */
    public function test_validation_fails_the_build(array $dirs, string $expected, array $extra): void
    {
        $roots = array_map(static fn (string $d): string => __DIR__ . '/Invalid/' . $d, $dirs);
        $result = $this->build($extra + ['roots' => $roots]);

        self::assertFalse($result->ok());
        self::assertNotEmpty(array_filter($result->errors, static fn (string $e): bool => str_contains($e, $expected)), "expected an error containing \"{$expected}\", got:\n" . implode("\n", $result->errors));
        self::assertFileDoesNotExist($this->out . '/registry/boot.php', 'a failed build writes nothing');
    }

    public function test_tools_list_budget_is_enforced_per_flavor(): void
    {
        $result = $this->build(['tools_list_budget' => 100]);

        self::assertFalse($result->ok());
        self::assertStringContainsString('tools/list estimate for flavor full', implode("\n", $result->errors));
    }
}
