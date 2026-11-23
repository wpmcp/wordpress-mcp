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

    /** @var list<string> Fixture plugin folders made by a test, removed in tear_down. */
    private array $fixture_dirs = [];

    public function tear_down(): void
    {
        foreach ($this->fixture_dirs as $dir) {
            shell_exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->fixture_dirs = [];
        parent::tear_down();
    }

    private function fixture_plugin(string $folder, string $header): string
    {
        $dir = WP_PLUGIN_DIR . '/' . $folder;
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->fixture_dirs[] = $dir;
        file_put_contents($dir . '/wpmcp.php', "<?php\n/**\n * Plugin Name: Fixture\n{$header} */\n");

        return $folder . '/wpmcp.php';
    }

    /**
     * The licensing service delivers the premium plugin in its own folder
     * (wpmcp-premium/ on every paying site), so any copy whose main file
     * declares a WPMCP Flavor is protected, whatever folder it is in. A
     * plugin without that header in the same kind of folder is not.
     */
    public function test_any_wp_mcp_copy_is_protected_in_any_folder(): void
    {
        $copy  = $this->fixture_plugin('wpmcp-premium', " * WPMCP Flavor: full\n");
        $other = $this->fixture_plugin('not-wpmcp-guard-fixture', '');

        $this->assertTrue(Package_Guard::is_protected_plugin($copy));
        $this->assertFalse(Package_Guard::is_protected_plugin($other));
        $this->assertFalse(Package_Guard::is_protected_plugin('../wpmcp-premium/wpmcp.php'), 'no path outside the plugins folder is read');
        $this->assertFalse(Package_Guard::is_protected_plugin('no-such-folder/wpmcp.php'));
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
