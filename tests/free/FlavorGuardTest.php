<?php

namespace WPMCP\Tests\Free;

/**
 * Two WP MCP builds (the full plugin, the wp.org directory build, a vertical
 * such as wpmcp-for-woocommerce) share the WPMCP_* constants, the \WPMCP\
 * namespace and every option, table and hook name. Only one of them may boot
 * per request, and which one must not depend on WordPress's load order: core
 * sorts active_plugins by basename, so 'wpmcp-for-woocommerce/...' loads
 * before 'wpmcp/wpmcp.php' and a plain defined('WPMCP_VERSION') guard in the
 * vertical never fires. Nor may it depend on directory names: the full plugin
 * and the directory build are both wpmcp.php, and the full plugin's directory
 * is whatever the installer chose. src/flavor-guard.php decides from the
 * active plugin list and the `WPMCP Flavor:` header each main file declares.
 */
class FlavorGuardTest extends \WP_UnitTestCase
{
    private const FULL  = 'full';
    private const WPORG = 'wporg';
    private const WOO   = 'woocommerce';

    /** @var string[] absolute paths of fixture main files written this test */
    private array $fixture_files = [];

    /** @var string[] fixture directories created this test, deepest first */
    private array $fixture_dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/src/flavor-guard.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->fixture_files as $file) {
            @unlink($file);
        }
        foreach ($this->fixture_dirs as $dir) {
            @rmdir($dir);
        }
        $this->fixture_files = [];
        $this->fixture_dirs  = [];
        update_option('active_plugins', []);
        parent::tearDown();
    }

    /**
     * Write a plugin main file under WP_PLUGIN_DIR. With a flavor it is a WP
     * MCP build; without one it is an unrelated plugin that happens to share
     * part of the name. Refuses to overwrite a real plugin.
     */
    private function install(string $plugin, ?string $flavor = null): string
    {
        $path = WP_PLUGIN_DIR . '/' . $plugin;
        $this->assertFileDoesNotExist($path, "fixture would clobber a real plugin: {$plugin}");

        $dir = dirname($path);
        if (! is_dir($dir)) {
            $this->assertTrue(mkdir($dir, 0777, true), "could not create {$dir}");
            $this->fixture_dirs[] = $dir;
        }

        $header = "<?php\n/**\n * Plugin Name: fixture {$plugin}\n";
        if (null !== $flavor) {
            $header .= " * WPMCP Flavor: {$flavor}\n";
        }
        $header .= " */\n";

        $this->assertNotFalse(file_put_contents($path, $header));
        $this->fixture_files[] = $path;

        return $path;
    }

    /**
     * Put the premium marker beside a fixture main file, as the premium zip
     * does.
     */
    private function mark_premium(string $main_file): void
    {
        $marker = dirname($main_file) . '/.wpmcp-pro';
        $this->assertNotFalse(file_put_contents($marker, "WP MCP premium build\n"));
        $this->fixture_files[] = $marker;
    }

    public function test_vertical_defers_when_the_full_plugin_is_active_but_has_not_loaded_yet(): void
    {
        $self = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        $this->install('wpmcp/wpmcp.php', self::FULL);
        update_option('active_plugins', ['wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', 'wpmcp/wpmcp.php']);

        // $already_loaded is false: this is the load-order case the old
        // defined('WPMCP_VERSION') guard missed entirely.
        $this->assertTrue(wpmcp_flavor_should_defer($self, self::WOO, false));
    }

    /**
     * Issue #1140: the wp.org vertical ships as safe-mcp-for-woocommerce/,
     * whose main file is not named wpmcp*.php. The guard filters only the
     * other active plugins on that prefix, so the vertical still finds and
     * defers to the full plugin, and the full plugin, which never defers to
     * a lower rank, still boots.
     */
    public function test_the_wporg_slug_vertical_defers_to_the_full_plugin_and_the_full_plugin_boots(): void
    {
        $self = $this->install('safe-mcp-for-woocommerce/safe-mcp-for-woocommerce.php', self::WOO);
        $full = $this->install('wpmcp/wpmcp.php', self::FULL);
        update_option('active_plugins', ['safe-mcp-for-woocommerce/safe-mcp-for-woocommerce.php', 'wpmcp/wpmcp.php']);

        $this->assertTrue(wpmcp_flavor_should_defer($self, self::WOO, false));
        $this->assertFalse(wpmcp_flavor_should_defer($full, self::FULL, false));
    }

    public function test_vertical_defers_to_the_directory_build_too(): void
    {
        $self = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        $this->install('wpmcp/wpmcp.php', self::WPORG);
        update_option('active_plugins', ['wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', 'wpmcp/wpmcp.php']);

        $this->assertTrue(wpmcp_flavor_should_defer($self, self::WOO, false));
    }

    public function test_vertical_boots_when_it_is_the_only_wpmcp_build_installed(): void
    {
        $self = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        update_option('active_plugins', ['wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', 'woocommerce/woocommerce.php']);

        $this->assertFalse(wpmcp_flavor_should_defer($self, self::WOO, false));
    }

    public function test_the_full_plugin_wins_whatever_directory_it_is_installed_in(): void
    {
        // The directory build owns the wp.org slug, so it always lives in
        // wpmcp/. The full plugin's directory sorts after it here, so core
        // loads the directory build first: exactly the case a path- or
        // load-order-based rule gets wrong.
        $wporg = $this->install('wpmcp/wpmcp.php', self::WPORG);
        $full  = $this->install('zz-wpmcp-main/wpmcp.php', self::FULL);
        update_option('active_plugins', ['wpmcp/wpmcp.php', 'zz-wpmcp-main/wpmcp.php']);

        $this->assertTrue(wpmcp_flavor_should_defer($wporg, self::WPORG, false), 'the directory build must stand down');
        $this->assertFalse(wpmcp_flavor_should_defer($full, self::FULL, false), 'the full plugin must boot');

        // And with the directories the licensing SDK's premium slug produces.
        $pro = $this->install('wpmcp-pro/wpmcp.php', self::FULL);
        update_option('active_plugins', ['wpmcp-pro/wpmcp.php', 'wpmcp/wpmcp.php']);

        $this->assertTrue(wpmcp_flavor_should_defer($wporg, self::WPORG, false));
        $this->assertFalse(wpmcp_flavor_should_defer($pro, self::FULL, false));
    }

    public function test_any_flavor_defers_once_another_copy_has_already_loaded(): void
    {
        update_option('active_plugins', []);

        // Nothing outranks the full plugin, so this is its only guard: it
        // must never redefine WPMCP_VERSION or boot a second Plugin instance.
        $this->assertTrue(wpmcp_flavor_should_defer(WPMCP_FILE, self::FULL, true));
        $this->assertTrue(wpmcp_flavor_should_defer(WP_PLUGIN_DIR . '/wpmcp/wpmcp.php', self::WPORG, true));
        $this->assertTrue(wpmcp_flavor_should_defer(WP_PLUGIN_DIR . '/x/wpmcp-for-woocommerce.php', self::WOO, true));
    }

    public function test_a_plugin_never_defers_to_itself(): void
    {
        $self = $this->install('wpmcp/wpmcp.php', self::FULL);
        update_option('active_plugins', ['wpmcp/wpmcp.php']);

        $this->assertFalse(wpmcp_flavor_should_defer($self, self::FULL, false));
    }

    public function test_two_copies_of_the_same_flavor_fall_back_to_load_order(): void
    {
        // Equal rank: neither outranks the other, so neither stands down on
        // the active list. The second one to load sees $already_loaded.
        $a = $this->install('wpmcp/wpmcp.php', self::FULL);
        $b = $this->install('wpmcp-pro/wpmcp.php', self::FULL);
        update_option('active_plugins', ['wpmcp/wpmcp.php', 'wpmcp-pro/wpmcp.php']);

        $this->assertFalse(wpmcp_flavor_should_defer($a, self::FULL, false));
        $this->assertFalse(wpmcp_flavor_should_defer($b, self::FULL, false));
        $this->assertTrue(wpmcp_flavor_should_defer($b, self::FULL, true));
    }

    public function test_an_unrelated_plugin_whose_path_contains_the_name_does_not_trigger_a_defer(): void
    {
        $self = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        // Same directory prefix, and the exact main-file basename in another
        // directory: neither declares a flavor, so neither is a WP MCP build.
        $this->install('wpmcp-companion/wpmcp-companion.php');
        $this->install('other-dir/wpmcp.php');
        update_option('active_plugins', [
            'wpmcp-for-woocommerce/wpmcp-for-woocommerce.php',
            'wpmcp-companion/wpmcp-companion.php',
            'other-dir/wpmcp.php',
            'some-wpmcp.php-theme/init.php',
        ]);

        $this->assertFalse(wpmcp_flavor_should_defer($self, self::WOO, false));
    }

    public function test_a_stale_active_entry_whose_file_is_gone_does_not_keep_the_vertical_down(): void
    {
        // Core skips a listed plugin whose file is missing but leaves the
        // entry until the Plugins screen prunes it. Deferring to it would
        // leave no build booted at all.
        $self = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        update_option('active_plugins', ['wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', 'wpmcp/wpmcp.php']);
        $this->assertFileDoesNotExist(WP_PLUGIN_DIR . '/wpmcp/wpmcp.php');

        $this->assertFalse(wpmcp_flavor_should_defer($self, self::WOO, false));
    }

    public function test_an_unknown_flavor_id_ranks_below_every_known_build(): void
    {
        $self  = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        $other = $this->install('wpmcp-for-future/wpmcp-for-future.php', 'future');
        update_option('active_plugins', ['wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', 'wpmcp-for-future/wpmcp-for-future.php']);

        $this->assertFalse(wpmcp_flavor_should_defer($self, self::WOO, false));
        $this->assertTrue(wpmcp_flavor_should_defer($other, 'future', false));
    }

    public function test_network_activated_full_plugin_is_seen_by_the_vertical(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('network activation only exists on multisite');
        }

        $self = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        $this->install('wpmcp/wpmcp.php', self::FULL);
        update_site_option('active_sitewide_plugins', ['wpmcp/wpmcp.php' => time()]);
        $this->assertTrue(wpmcp_flavor_should_defer($self, self::WOO, false));
        update_site_option('active_sitewide_plugins', []);
    }

    public function test_the_rank_order_is_full_then_wporg_then_woocommerce(): void
    {
        $this->assertGreaterThan(wpmcp_flavor_rank(self::WPORG), wpmcp_flavor_rank(self::FULL));
        $this->assertGreaterThan(wpmcp_flavor_rank(self::WOO), wpmcp_flavor_rank(self::WPORG));
        $this->assertGreaterThan(wpmcp_flavor_rank('anything-else'), wpmcp_flavor_rank(self::WOO));
    }

    /**
     * Every shipped main file must call the guard before it defines the
     * shared constants or registers its Composer autoloader (a guard that
     * runs after either has already caused the collision), and the flavor id
     * it passes must be the one its own header declares, because that header
     * is what the other builds rank it by.
     */
    public function test_all_three_main_files_call_the_guard_before_defining_constants(): void
    {
        $root  = dirname(__DIR__, 2);
        $files = [
            $root . '/wpmcp.php'                                                => self::FULL,
            $root . '/scripts/flavors/wporg/wpmcp.php'                          => self::WPORG,
            $root . '/scripts/flavors/woocommerce/safe-mcp-for-woocommerce.php'   => self::WOO,
        ];

        foreach ($files as $file => $expected_flavor) {
            $source = file_get_contents($file);
            $this->assertNotFalse($source, "unreadable main file: {$file}");

            $guard      = strpos($source, 'wpmcp_flavor_should_defer(');
            $define     = strpos($source, "define( 'WPMCP_VERSION'");
            $autoloader = strpos($source, 'vendor/autoload.php');

            $this->assertNotFalse($guard, "no coexistence guard in {$file}");
            $this->assertNotFalse($define, "no WPMCP_VERSION define in {$file}");
            $this->assertNotFalse($autoloader, "no autoloader require in {$file}");
            $this->assertLessThan($define, $guard, "guard runs after the constants in {$file}");
            $this->assertLessThan($autoloader, $guard, "guard runs after the autoloader in {$file}");

            $this->assertSame(1, preg_match("/wpmcp_flavor_should_defer\(\s*__FILE__,\s*'([a-z]+)'/", $source, $m), "guard call in {$file} does not pass __FILE__ and a literal flavor id");
            $this->assertSame($expected_flavor, $m[1], "wrong flavor id passed in {$file}");
            $this->assertSame($expected_flavor, wpmcp_flavor_of($file), "WPMCP Flavor header in {$file} does not match the id it passes");
            $this->assertStringContainsString('admin_notices', $source, "the losing build shows no notice in {$file}");
        }
    }

    // ------------------------------------------------------------ premium build

    public function test_the_premium_marker_adds_less_than_one_flavor_step(): void
    {
        $free    = $this->install('wpmcp/wpmcp.php', self::FULL);
        $premium = $this->install('wpmcp-pro/wpmcp.php', self::FULL);
        $this->mark_premium($premium);
        $wporg = $this->install('wpmcp-wporg/wpmcp.php', self::WPORG);
        $this->mark_premium($wporg);

        $this->assertGreaterThan(wpmcp_build_rank($free, self::FULL), wpmcp_build_rank($premium, self::FULL));
        $this->assertGreaterThan(wpmcp_build_rank($wporg, self::WPORG), wpmcp_build_rank($free, self::FULL), 'a marker must never lift a lower flavor over the full plugin');
        $this->assertSame(wpmcp_build_rank($free, self::FULL), wpmcp_build_rank(WP_PLUGIN_DIR . '/missing/wpmcp.php', self::FULL));
    }

    /**
     * The free and premium zips are both 'full' builds named wpmcp.php. The
     * premium copy keeps the request whichever directory sorts first, so the
     * free copy never boots beside it.
     */
    public function test_the_premium_copy_outranks_a_free_copy_that_loads_first(): void
    {
        $this->assert_premium_wins('wpmcp/wpmcp.php', 'wpmcp-pro/wpmcp.php');
    }

    public function test_the_premium_copy_outranks_a_free_copy_that_loads_after_it(): void
    {
        $this->assert_premium_wins('zz-wpmcp/wpmcp.php', 'wpmcp-pro/wpmcp.php');
    }

    private function assert_premium_wins(string $free_plugin, string $premium_plugin): void
    {
        $free    = $this->install($free_plugin, self::FULL);
        $premium = $this->install($premium_plugin, self::FULL);
        $this->mark_premium($premium);
        $active = [ $free_plugin, $premium_plugin ];
        sort($active);
        update_option('active_plugins', $active);

        $this->assertTrue(wpmcp_flavor_should_defer($free, self::FULL, false), "free copy in {$free_plugin} must stand down");
        $this->assertFalse(wpmcp_flavor_should_defer($premium, self::FULL, false), "premium copy in {$premium_plugin} must boot");
    }

    public function test_two_premium_copies_still_fall_back_to_load_order(): void
    {
        $a = $this->install('wpmcp/wpmcp.php', self::FULL);
        $b = $this->install('wpmcp-pro/wpmcp.php', self::FULL);
        $this->mark_premium($a);
        $this->mark_premium($b);
        update_option('active_plugins', ['wpmcp-pro/wpmcp.php', 'wpmcp/wpmcp.php']);

        $this->assertFalse(wpmcp_flavor_should_defer($a, self::FULL, false));
        $this->assertFalse(wpmcp_flavor_should_defer($b, self::FULL, false));
    }

    public function test_the_premium_copy_deactivates_free_full_copies_only(): void
    {
        $premium = $this->install('wpmcp-pro/wpmcp.php', self::FULL);
        $this->mark_premium($premium);
        $this->install('wpmcp/wpmcp.php', self::FULL);
        $other_premium = $this->install('wpmcp-pro-2/wpmcp.php', self::FULL);
        $this->mark_premium($other_premium);
        $this->install('wpmcp-wporg/wpmcp.php', self::WPORG);
        $this->install('wpmcp-companion/wpmcp-companion.php');
        update_option('active_plugins', [
            'wpmcp-companion/wpmcp-companion.php',
            'wpmcp-pro-2/wpmcp.php',
            'wpmcp-pro/wpmcp.php',
            'wpmcp-wporg/wpmcp.php',
            'wpmcp/wpmcp.php',
        ]);

        $this->assertSame(['wpmcp/wpmcp.php'], wpmcp_pro_deactivate_free_copies($premium));

        $active = (array) get_option('active_plugins', []);
        $this->assertNotContains('wpmcp/wpmcp.php', $active, 'the free copy stayed active');
        foreach (['wpmcp-pro/wpmcp.php', 'wpmcp-pro-2/wpmcp.php', 'wpmcp-wporg/wpmcp.php', 'wpmcp-companion/wpmcp-companion.php'] as $kept) {
            $this->assertContains($kept, $active, "{$kept} was deactivated");
        }
    }

    public function test_a_free_copy_deactivates_nothing(): void
    {
        $free = $this->install('wpmcp/wpmcp.php', self::FULL);
        $this->install('zz-wpmcp/wpmcp.php', self::FULL);
        update_option('active_plugins', ['wpmcp/wpmcp.php', 'zz-wpmcp/wpmcp.php']);

        $this->assertSame([], wpmcp_pro_deactivate_free_copies($free));
        $this->assertSame(['wpmcp/wpmcp.php', 'zz-wpmcp/wpmcp.php'], array_values((array) get_option('active_plugins', [])));
    }

    public function test_the_premium_copy_deactivates_a_network_activated_free_copy(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('network activation only exists on multisite');
        }

        $premium = $this->install('wpmcp-pro/wpmcp.php', self::FULL);
        $this->mark_premium($premium);
        $this->install('wpmcp/wpmcp.php', self::FULL);
        update_site_option('active_sitewide_plugins', ['wpmcp/wpmcp.php' => time(), 'wpmcp-pro/wpmcp.php' => time()]);

        try {
            $this->assertSame(['wpmcp/wpmcp.php'], wpmcp_pro_deactivate_free_copies($premium));
            $network = (array) get_site_option('active_sitewide_plugins', []);
            $this->assertArrayNotHasKey('wpmcp/wpmcp.php', $network);
            $this->assertArrayHasKey('wpmcp-pro/wpmcp.php', $network);
        } finally {
            update_site_option('active_sitewide_plugins', []);
        }
    }

    public function test_the_main_file_defines_the_premium_flag_after_the_guard_and_wires_the_takeover(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/wpmcp.php');

        $guard   = strpos($source, 'wpmcp_flavor_should_defer(');
        $flag    = strpos($source, "define( 'WPMCP_PREMIUM_BUILD', is_readable( __DIR__ . '/.wpmcp-pro' ) )");
        $version = strpos($source, "define( 'WPMCP_VERSION'");
        $this->assertNotFalse($flag, 'WPMCP_PREMIUM_BUILD is not defined from the marker');
        $this->assertLessThan($flag, $guard, 'a copy that stands down must not define the flag');
        $this->assertLessThan($version, $flag);

        // A copy that stands down returns before anything after the guard,
        // so the takeover is wired ahead of it.
        $takeover = strpos($source, 'wpmcp_pro_deactivate_free_copies( __FILE__ )');
        $this->assertNotFalse($takeover);
        $this->assertLessThan($guard, $takeover);
        $this->assertStringContainsString('register_activation_hook( __FILE__', substr($source, 0, $guard));
        $this->assertStringContainsString("add_action( 'admin_init'", substr($source, 0, $guard));
    }

    // ------------------------------------------------------------ other builds (uninstall and deactivation)

    public function test_other_build_installed_sees_an_inactive_sibling_but_not_itself_or_unrelated_plugins(): void
    {
        $this->install('wpmcp/wpmcp.php', self::FULL);
        $this->install('wpmcp-companion/wpmcp-companion.php');
        wp_clean_plugins_cache(false);

        try {
            $this->assertFalse(wpmcp_other_build_installed(WP_PLUGIN_DIR . '/wpmcp'), 'its own folder or an unrelated plugin counted as another build');

            $this->install('wpmcp-pro/wpmcp.php', self::FULL);
            wp_clean_plugins_cache(false);
            $this->assertTrue(wpmcp_other_build_installed(WP_PLUGIN_DIR . '/wpmcp/'));
            $this->assertTrue(wpmcp_other_build_installed(WP_PLUGIN_DIR . '/wpmcp-pro'), 'the premium copy must see the free copy');
        } finally {
            wp_clean_plugins_cache(false);
        }
    }

    public function test_other_build_active_reads_only_the_active_list(): void
    {
        $this->install('wpmcp/wpmcp.php', self::FULL);
        $this->install('wpmcp-pro/wpmcp.php', self::FULL);
        $this->install('wpmcp-companion/wpmcp-companion.php');

        update_option('active_plugins', ['wpmcp/wpmcp.php', 'wpmcp-companion/wpmcp-companion.php']);
        $this->assertFalse(wpmcp_other_build_active(WP_PLUGIN_DIR . '/wpmcp'), 'a build counted itself or an unrelated plugin');

        update_option('active_plugins', ['wpmcp/wpmcp.php', 'wpmcp-pro/wpmcp.php']);
        $this->assertTrue(wpmcp_other_build_active(WP_PLUGIN_DIR . '/wpmcp'));
    }
}
