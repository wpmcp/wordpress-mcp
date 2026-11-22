<?php

namespace WPMCP\Tests\Pro\Governance;

use WPMCP\Admin\Opt_In_Settings_Page;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Governance\Opt_In_Settings;
use WPMCP\Tests\Free\Governance\OptInSettingsTest;
use WPMCP\Tests\Support\Edition_Tree;

/**
 * The Pro half of the issue #823 part 4 opt-in settings test
 * (tests/free/Governance/OptInSettingsTest): the groups whose owner file is
 * Pro (OptInSettingsTest::PRO_OWNED_GROUPS) are declared by the Pro
 * sources, named by their owners and listed with a warning, and the
 * WooCommerce per-op toggle opens the default-off ops.
 */
class OptInSettingsProTest extends \WP_UnitTestCase
{
    private int $admin_id;

    protected function setUp(): void
    {
        parent::setUp();
        Edition_Tree::require_pro($this);
        Opt_In_Settings::register();
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($this->admin_id);
        }
        wp_set_current_user($this->admin_id);
        delete_option(Opt_In_Settings::OPTION);
        delete_site_option(Opt_In_Settings::NETWORK_OPTION);
        delete_option(Governance_Audit_Log::OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(Opt_In_Settings::OPTION);
        delete_site_option(Opt_In_Settings::NETWORK_OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        parent::tearDown();
    }

    /**
     * Every opt_in_filter a Pro pack declares, and the WooCommerce per-op
     * switch, has a group, and every Pro-owned group is declared here.
     */
    public function test_every_opt_in_filter_in_src_has_a_group(): void
    {
        $found  = [ 'wpmcp_woo_op_enabled' => true ] + OptInSettingsTest::declared_filters($this, Edition_Tree::pro_sources());
        $groups = Opt_In_Settings::all_groups();
        $this->assertSame([], array_values(array_diff(array_keys($found), array_keys($groups))), 'opt_in_filter values with no settings toggle');
        $this->assertSame([], array_values(array_diff(OptInSettingsTest::PRO_OWNED_GROUPS, array_keys($found))), 'toggles for filters no pack declares');

        $pro = Edition_Tree::pro_sources();
        foreach (OptInSettingsTest::PRO_OWNED_GROUPS as $filter) {
            $owner = $pro[ $groups[ $filter ]['owner'] ] ?? null;
            $this->assertNotNull($owner, $filter . ': its owner ' . $groups[ $filter ]['owner'] . ' is not in the Pro sources');
            $this->assertStringContainsString("'" . $filter . "'", (string) file_get_contents((string) $owner), $filter . ' is not named by its owner file');
        }
    }

    public function test_every_pro_group_ships_and_has_a_label(): void
    {
        $missing = array_values(array_diff(OptInSettingsTest::PRO_OWNED_GROUPS, array_keys(Opt_In_Settings::groups())));
        $this->assertSame([], $missing, 'Pro-owned opt-in groups that do not ship with the Pro code present');
        $missing = array_values(array_diff(OptInSettingsTest::PRO_OWNED_GROUPS, array_keys(Opt_In_Settings::labels())));
        $this->assertSame([], $missing, 'Opt-in groups with no label in Opt_In_Settings::labels()');
    }

    public function test_woo_toggle_opens_default_off_ops_and_keeps_args(): void
    {
        $this->assertFalse((bool) apply_filters('wpmcp_woo_op_enabled', false, 'subscriptions.cancel'));
        Opt_In_Settings::set('wpmcp_woo_op_enabled', true, 'site');
        $this->assertTrue((bool) apply_filters('wpmcp_woo_op_enabled', false, 'subscriptions.cancel'));
        $this->assertTrue((bool) apply_filters('wpmcp_woo_op_enabled', true, 'products.update'));
    }

    public function test_destructive_groups_carry_a_warning(): void
    {
        $rows = (new Opt_In_Settings_Page())->rows('site');
        $this->assertTrue($rows['wpmcp_woo_op_enabled']['destructive'] ?? null);
        $this->assertNotSame('', $rows['wpmcp_woo_op_enabled']['warning']);
        $this->assertFalse($rows['wpmcp_enable_otter_settings_write']['destructive'] ?? null);
        $this->assertSame('', $rows['wpmcp_enable_otter_settings_write']['warning']);
    }
}
