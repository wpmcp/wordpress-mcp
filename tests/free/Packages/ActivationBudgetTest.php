<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Activation_Budget;

/**
 * Issue #1201: install-plugin with activate:true does not start an
 * activation that could outlast the client's tool timeout. A large plugin
 * (WooCommerce's activation installs tables and runs migrations), or an
 * install that already used most of the client's budget, is left installed
 * and inactive with the activate-plugin call that finishes the job.
 */
final class ActivationBudgetTest extends \WP_UnitTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'wpmcp-1201-' . wp_generate_password(6, false, false);
        wp_mkdir_p(WP_PLUGIN_DIR . '/' . $this->dir);
        file_put_contents(WP_PLUGIN_DIR . '/' . $this->dir . '/plugin.php', "<?php\n/*\nPlugin Name: WPMCP 1201\n*/\n");
        file_put_contents(WP_PLUGIN_DIR . '/' . $this->dir . '/data.txt', str_repeat('x', 4096));
    }

    protected function tearDown(): void
    {
        @unlink(WP_PLUGIN_DIR . '/' . $this->dir . '/plugin.php');
        @unlink(WP_PLUGIN_DIR . '/' . $this->dir . '/data.txt');
        @rmdir(WP_PLUGIN_DIR . '/' . $this->dir);
        remove_all_filters('wpmcp_install_plugin_time_left');
        remove_all_filters('wpmcp_install_plugin_elapsed');
        remove_all_filters('wpmcp_install_plugin_client_budget');
        remove_all_filters('wpmcp_install_plugin_heavy_bytes');
        parent::tearDown();
    }

    private function file(): string
    {
        return $this->dir . '/plugin.php';
    }

    public function test_a_small_plugin_installed_quickly_is_activated_in_the_same_call(): void
    {
        add_filter('wpmcp_install_plugin_elapsed', static fn () => 3.0);
        add_filter('wpmcp_install_plugin_time_left', static fn () => null);

        $this->assertNull(Activation_Budget::deferral_reason($this->file()));
    }

    public function test_a_large_plugin_is_left_for_a_separate_activate_plugin_call(): void
    {
        add_filter('wpmcp_install_plugin_elapsed', static fn () => 3.0);
        add_filter('wpmcp_install_plugin_heavy_bytes', static fn () => 1024);

        $reason = Activation_Budget::deferral_reason($this->file());

        $this->assertNotNull($reason);
        $this->assertStringContainsString('large plugin', (string) $reason);
        $this->assertSame(4096 + strlen("<?php\n/*\nPlugin Name: WPMCP 1201\n*/\n"), Activation_Budget::plugin_bytes($this->file()));
    }

    public function test_an_install_that_used_most_of_the_client_budget_defers_the_activation(): void
    {
        add_filter('wpmcp_install_plugin_client_budget', static fn () => 60);
        add_filter('wpmcp_install_plugin_elapsed', static fn () => 50.0);

        $reason = Activation_Budget::deferral_reason($this->file());

        $this->assertNotNull($reason);
        $this->assertStringContainsString('timing out', (string) $reason);
    }

    public function test_the_php_time_limit_check_still_applies(): void
    {
        add_filter('wpmcp_install_plugin_elapsed', static fn () => 1.0);
        add_filter('wpmcp_install_plugin_time_left', static fn () => 5.0);

        $this->assertStringContainsString('PHP time limit', (string) Activation_Budget::deferral_reason($this->file()));
    }
}
