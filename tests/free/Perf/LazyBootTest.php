<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Perf;

use WPMCP\Integrations\Integration_Dispatcher;
use WPMCP\Kernel\Kernel_Boot;
use WPMCP\Kernel\Registry;
use WPMCP\Plugin;
use WPMCP\Tests\Free\Integrations\Fixture_Integration;

/**
 * What 2.0.1 stopped loading on every request (tests/free/Perf/FootprintTest.php
 * counts the files) without changing what runs:
 *
 *  - the rollback refreshers are hooked after a check that does not load
 *    their classes (Kernel_Boot::ships());
 *  - the #[On_Rollback] rows of modules attach on the first rollback action
 *    that fires, in that same firing, instead of on every request;
 *  - list-operations leaves out the input_schema of ops that cannot run on
 *    the site, and args {"include_schemas": true} answers the full catalog.
 */
final class LazyBootTest extends \WP_UnitTestCase
{
    private const MODULE = [
        'id'        => 'acme',
        'class'     => 'WPMCP\\Modules\\Acme\\Module',
        'namespace' => 'WPMCP\\Modules\\Acme\\',
        'dir'       => 'src/Modules/Acme',
        'tier'      => 'free',
        'requires'  => null,
    ];

    protected function tearDown(): void
    {
        Plugin::instance()->kernel()->use_registry_for_tests(null);
        Rollback_Probe::$calls = [];
        Fixture_Integration::reset();
        remove_all_filters('wpmcp_rollback_options_restored');
        remove_all_filters('wpmcp_rollback_post_refusal');
        remove_all_actions('trashed_post');
        parent::tearDown();
    }

    public function test_ships_answers_like_class_exists(): void
    {
        $this->assertTrue(Kernel_Boot::ships(\WPMCP\Integrations\Theme_Framework_Pack::class));
        $this->assertTrue(Kernel_Boot::ships(Rollback_Probe::class), 'a class defined outside src/ still counts, as class_exists() said');
        $this->assertFalse(Kernel_Boot::ships('WPMCP\\Integrations\\Pruned_By_This_Build'));
        $this->assertFalse(Kernel_Boot::ships('WPMCP\\Modules\\Pruned\\Module'));
    }

    public function test_module_rollback_rows_attach_on_the_first_rollback_and_run_in_it(): void
    {
        $this->use_rows([ $this->row('wpmcp_rollback_options_restored') ]);
        remove_all_filters('wpmcp_rollback_options_restored');

        Plugin::instance()->kernel()->attach_rollback_hooks_on_first_rollback();
        $this->assertFalse(has_action('wpmcp_rollback_options_restored', [ Rollback_Probe::class, 'after_restore' ]), 'not attached before a rollback');

        do_action('wpmcp_rollback_options_restored', [ 'blogname' ]);
        $this->assertSame([ [ [ 'blogname' ] ] ], Rollback_Probe::$calls, 'the first rollback action already reaches the row');

        do_action('wpmcp_rollback_options_restored', [ 'blogdescription' ]);
        $this->assertCount(2, Rollback_Probe::$calls, 'attached once, not once per firing');
    }

    public function test_a_rollback_filter_keeps_its_value_through_the_stand_in(): void
    {
        $this->use_rows([ $this->row('wpmcp_rollback_post_refusal', true) ]);
        remove_all_filters('wpmcp_rollback_post_refusal');

        Plugin::instance()->kernel()->attach_rollback_hooks_on_first_rollback();

        $this->assertSame('refused', apply_filters('wpmcp_rollback_post_refusal', 'refused', null));
        $this->assertCount(1, Rollback_Probe::$calls);
    }

    public function test_a_row_on_another_hook_attaches_at_once(): void
    {
        $this->use_rows([ $this->row('trashed_post') ]);
        remove_all_actions('trashed_post');

        Plugin::instance()->kernel()->attach_rollback_hooks_on_first_rollback();

        $this->assertNotFalse(has_action('trashed_post', [ Rollback_Probe::class, 'after_restore' ]));
    }

    public function test_list_operations_leaves_out_the_schemas_of_ops_that_cannot_run(): void
    {
        Fixture_Integration::$dependency_present = false;
        $integration = new Fixture_Integration();

        $out = $integration->handle_read([ 'operation' => 'list-operations' ]);
        $ops = array_column($out['result']['operations'], null, 'name');

        $this->assertFalse($ops['needs-dependency']['dependency_met']);
        $this->assertArrayNotHasKey('input_schema', $ops['needs-dependency']);
        $this->assertSame(
            [ 'name', 'mode', 'description', 'capability', 'enabled', 'requires_confirm', 'dependency_met' ],
            array_keys($ops['needs-dependency']),
            'every other field stays'
        );
        $this->assertArrayHasKey('input_schema', $ops['ping'], 'an op that can run keeps its schema');
        $this->assertSame(Integration_Dispatcher::SCHEMA_NOTE, $out['result']['schema_note']);
        $this->assertSame(count($integration->catalog()['operations']), count($ops), 'every op is still listed');
    }

    public function test_include_schemas_answers_the_full_catalog(): void
    {
        Fixture_Integration::$dependency_present = false;
        $integration = new Fixture_Integration();

        foreach ([ true, 'true', 1 ] as $flag) {
            $out = $integration->handle_read([ 'operation' => 'list-operations', 'args' => [ 'include_schemas' => $flag ] ]);
            $this->assertSame($integration->catalog(), $out['result']);
        }
    }

    public function test_no_note_when_every_op_can_run(): void
    {
        $integration = new class () extends Fixture_Integration {
            protected function operations(): array
            {
                return array_intersect_key(parent::operations(), [ 'ping' => true ]);
            }
        };

        $out = $integration->handle_read([ 'operation' => 'list-operations' ]);

        $this->assertSame($integration->catalog(), $out['result']);
        $this->assertArrayNotHasKey('schema_note', $out['result']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function use_rows(array $rows): void
    {
        Plugin::instance()->kernel()->use_registry_for_tests(Registry::from_arrays([
            'modules' => [ 'acme' => self::MODULE ],
            'hooks'   => $rows,
        ]));
    }

    /** @return array<string, mixed> */
    private function row(string $hook, bool $filter = false): array
    {
        return [
            'module'   => 'acme',
            'kind'     => 'rollback',
            'hook'     => $hook,
            'class'    => Rollback_Probe::class,
            'method'   => $filter ? 'refusal' : 'after_restore',
            'static'   => true,
            'priority' => 10,
            'args'     => 1,
            'filter'   => $filter,
            'contexts' => [],
        ];
    }
}

/** A static #[On_Rollback] target that records its calls. */
final class Rollback_Probe
{
    /** @var list<array<int, mixed>> */
    public static array $calls = [];

    public static function after_restore(mixed ...$args): void
    {
        self::$calls[] = $args;
    }

    public static function refusal(mixed $why = null): mixed
    {
        self::$calls[] = [ $why ];
        return $why;
    }
}
