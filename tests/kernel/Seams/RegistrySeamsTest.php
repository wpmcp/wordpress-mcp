<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Seams;

use PHPUnit\Framework\TestCase;
use WPMCP\Data_Registry;
use WPMCP\Governance\Opt_In_Settings;
use WPMCP\Integrations\Plugin_Refusal;
use WPMCP\Kernel\Registry;
use WPMCP\Kernel\Registry_Rows;
use WPMCP\Plugin;

/**
 * The D11 seams: the legacy central lists take in what modules declare
 * through the compiled registry, literal rows first, and a key declared
 * both ways is an error.
 */
final class RegistrySeamsTest extends TestCase
{
    private const MODULE = [
        'id' => 'acme',
        'class' => 'WPMCP\\Modules\\Acme\\Module',
        'namespace' => 'WPMCP\\Modules\\Acme\\',
        'dir' => 'src/Modules/Acme',
    ];

    protected function tearDown(): void
    {
        Plugin::instance()->kernel()->use_registry_for_tests(null);
        Data_Registry::reset_for_tests();
    }

    public function test_merge_keeps_literal_rows_first_then_registry_rows(): void
    {
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], Registry_Rows::merge(['a' => 1, 'b' => 2], ['c' => 3], 'row'));
        $this->assertSame(['a' => 1], Registry_Rows::merge(['a' => 1], [], 'row'));
    }

    public function test_merge_refuses_a_key_declared_both_ways(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Opt-in group wpmcp_x is declared both in a central list and by a module');
        Registry_Rows::merge(['wpmcp_x' => 1], ['wpmcp_x' => 2], 'Opt-in group');
    }

    public function test_an_empty_registry_leaves_every_list_as_it_was(): void
    {
        Plugin::instance()->kernel()->use_registry_for_tests(Registry::from_arrays([]));
        $groups = Opt_In_Settings::all_groups();
        $this->assertArrayHasKey('wpmcp_enable_pmpro_write', $groups);
        $this->assertSame('wpmcp_woo_op_enabled', array_key_first($groups));
        $this->assertSame('https://wordpress.org/plugins/tablepress/', Plugin_Refusal::install_url('TablePress'));
        Data_Registry::set_module_data_for_tests([]);
        $this->assertSame(Data_Registry::TABLES, Data_Registry::tables());
    }

    public function test_opt_in_groups_take_in_module_gates_after_the_literal_ones(): void
    {
        Plugin::instance()->kernel()->use_registry_for_tests(Registry::from_arrays([
            'modules' => ['acme' => self::MODULE],
            'opt_in_gates' => [
                'wpmcp_enable_acme_write' => ['module' => 'acme', 'class' => 'WPMCP\\Modules\\Acme\\Ops\\Acme_Ops', 'destructive' => true, 'labels' => null, 'group' => null],
            ],
        ]));
        $groups = Opt_In_Settings::all_groups();
        $this->assertSame('wpmcp_enable_acme_write', array_key_last($groups));
        $this->assertSame(['owner' => 'src/Modules/Acme/Ops/Acme_Ops.php', 'destructive' => true], $groups['wpmcp_enable_acme_write']);
    }

    public function test_a_module_gate_that_repeats_a_literal_one_is_an_error(): void
    {
        Plugin::instance()->kernel()->use_registry_for_tests(Registry::from_arrays([
            'modules' => ['acme' => self::MODULE],
            'opt_in_gates' => [
                'wpmcp_enable_pmpro_write' => ['module' => 'acme', 'class' => 'WPMCP\\Modules\\Acme\\Ops\\Acme_Ops', 'destructive' => true, 'labels' => null, 'group' => null],
            ],
        ]));
        $this->expectException(\LogicException::class);
        Opt_In_Settings::all_groups();
    }

    public function test_data_registry_takes_in_module_tables_and_stores(): void
    {
        Data_Registry::set_module_data_for_tests([
            'acme' => ['options' => ['acme_settings'], 'tables' => ['wpmcp_acme_rows'], 'cron' => [], 'post_types' => [], 'user_meta' => ['acme_seen']],
        ]);
        $this->assertSame([...Data_Registry::TABLES, 'wpmcp_acme_rows'], Data_Registry::tables());
        $this->assertSame(['acme_settings'], Data_Registry::module_options());
        $this->assertSame(['acme_seen'], Data_Registry::module_user_meta());
        $this->assertTrue(Data_Registry::owns_option('acme_settings'));
        $this->assertFalse(Data_Registry::owns_option('acme_other'));
    }

    public function test_data_registry_refuses_a_table_declared_both_ways(): void
    {
        Data_Registry::set_module_data_for_tests(['acme' => ['tables' => [Data_Registry::TABLES[0]]]]);
        $this->expectException(\LogicException::class);
        Data_Registry::tables();
    }
}
