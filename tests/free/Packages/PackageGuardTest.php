<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Package_Guard;

class PackageGuardTest extends \WP_UnitTestCase
{
    public function test_protects_wpmcp_and_elementor_plugin_slugs(): void
    {
        $this->assertTrue(Package_Guard::is_protected_plugin('wpmcp/wpmcp.php'));
        $this->assertTrue(Package_Guard::is_protected_plugin('elementor/elementor.php'));
        $this->assertTrue(Package_Guard::is_protected_plugin('elementor-pro/elementor-pro.php'));
    }

    public function test_does_not_protect_unrelated_plugin_slug(): void
    {
        $this->assertFalse(Package_Guard::is_protected_plugin('akismet/akismet.php'));
    }

    public function test_filesystem_ready_true_for_direct_method(): void
    {
        add_filter('filesystem_method', fn () => 'direct');
        $this->assertTrue(Package_Guard::filesystem_ready());
    }

    public function test_the_premium_build_folder_is_protected_too(): void
    {
        $this->assertTrue(\WPMCP\Tools\Packages\Package_Guard::is_protected_plugin('wpmcp-pro/wpmcp.php'));
        $this->assertTrue(\WPMCP\Tools\Packages\Package_Guard::is_protected_plugin('wpmcp/wpmcp.php'));
    }

    /**
     * The licensing service delivers the premium plugin in wpmcp-premium/
     * (the product's premium slug there), and that is where every paying
     * site has it, so that folder is protected like the other two.
     */
    public function test_the_folder_the_licensing_service_installs_pro_into_is_protected(): void
    {
        $this->assertTrue(Package_Guard::is_protected_plugin('wpmcp-premium/wpmcp.php'));
    }

    /**
     * Whatever folder the running copy sits in, it never lets a tool
     * deactivate, delete or update itself: a renamed install protects its own
     * main file, as is_protected_plugin_dir() already does for its folder.
     */
    public function test_the_running_copy_is_protected_in_any_folder(): void
    {
        $self = plugin_basename(WPMCP_FILE);
        $this->assertNotContains($self, ['wpmcp/wpmcp.php', 'wpmcp-pro/wpmcp.php', 'wpmcp-premium/wpmcp.php'], 'this checkout must not sit in a listed folder, or the test proves nothing');
        $this->assertTrue(Package_Guard::is_protected_plugin($self));
    }
}
