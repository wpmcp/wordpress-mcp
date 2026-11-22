<?php

namespace WPMCP\Tests\Pro\Uninstall;

use WPMCP\Data_Registry;
use WPMCP\Tests\Free\Uninstall\DataRegistryGuardTest;
use WPMCP\Tests\Support\Edition_Tree;
use WPMCP\Tools\WidgetBuilder\Compiler\Compiled_Widget_Manifest;

/**
 * The Pro half of the data ownership registry guard (issue #480,
 * tests/free/Uninstall/DataRegistryGuardTest): the same scan over the Pro
 * sources, with the allowlist entries of Pro files and the foreign keys
 * only Pro code writes, and the Pro-owned stores the public half lists by
 * value pinned to their classes.
 */
class DataRegistryGuardProTest extends \WP_UnitTestCase
{
    /**
     * Pro keys owned by WordPress core or another plugin that a Pro tool
     * writes on the site's behalf, beyond the public half's FOREIGN (which
     * also applies here). Uninstall must never delete these. An entry
     * ending in '*' is a prefix.
     */
    private const FOREIGN = [
        'option'         => [
            // WooCommerce's payment gateway order, written by the gateway
            // reorder op of the Woo catalog.
            'woocommerce_gateway_order',
            // FunnelKit Cart's settings, written by cart.update-settings on
            // the woo-write pair (issue #832).
            'fkcart_settings',
            // Otter Blocks' feature switches and disabled blocks, set by
            // update-otter-settings on the block-suites pair (issue #647).
            'themeisle_blocks_settings_*',
            'themeisle_disabled_blocks',
            // Avada's global options, written by the Avada design_system
            // scope on the builder pair (issue #637).
            'fusion_options',
            // WPBakery's saved templates, written by the builder pair's
            // templates scope (issue #639).
            'wpb_js_templates',
        ],
        'transient'      => [],
        'site_transient' => [],
        'site_option'    => [],
        'user_meta'      => [],
    ];

    /**
     * Pro files whose writes take a key the code cannot know statically,
     * with the reason (see DYNAMIC_KEY_FILES in the public half).
     */
    private const DYNAMIC_KEY_FILES = [
        'src/Cloud/Settings_Sync.php'                        => 'applies synced site settings the owner chose',
        'src/Tools/Builders/Builder_Design_Write.php'        => 'page builder design options',
        'src/Tools/WooCommerce/Catalog/Shipping_Ops.php'     => 'WooCommerce shipping method settings',
        'src/Tools/Analysis/SeoData/Seo_Data_Lookup.php'     => 'cache key built by cache_name() from CACHE_PREFIX (wpmcp_seod_)',
        'src/Integrations/Block_Suite_Settings.php'          => 'Kadence Blocks and Spectra settings options from its allowlist (issue #648)',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Edition_Tree::require_pro($this);
    }

    /** @return array<string, string[]> the public FOREIGN plus this half's */
    private static function all_foreign(): array
    {
        $all = DataRegistryGuardTest::FOREIGN;
        foreach (self::FOREIGN as $kind => $entries) {
            $all[ $kind ] = array_merge($all[ $kind ] ?? [], $entries);
        }
        return $all;
    }

    public function test_every_written_key_is_registered_or_declared_foreign(): void
    {
        $writes = DataRegistryGuardTest::writes(Edition_Tree::pro_sources());
        $this->assertGreaterThan(10, count($writes), 'The scan found too few writes, so it is not looking in the right place.');

        $found = DataRegistryGuardTest::key_problems($writes, self::DYNAMIC_KEY_FILES, self::all_foreign());
        $this->assertSame([], $found['dynamic_stale'], 'DYNAMIC_KEY_FILES lists files with no dynamic write any more.');
        $this->assertSame([], $found['unresolved'], "Writes whose key could not be resolved; resolve it to a constant or add the file to DYNAMIC_KEY_FILES with the reason:\n" . implode("\n", $found['unresolved']));
        $this->assertSame([], $found['missing'], "Keys WP MCP writes that Data_Registry does not cover, so deleting the plugin would leave them behind:\n" . implode("\n", $found['missing']));
        $this->assertSame([], array_values(array_diff(DataRegistryGuardTest::declared(self::FOREIGN), array_keys($found['foreign_used']))), 'FOREIGN lists keys nothing in src/ writes any more.');
        $this->assertSame([], $found['unknown'], "Keys written that are neither WP MCP's (wpmcp_ / _wpmcp_) nor declared FOREIGN here. A new store must use a registered prefix:\n" . implode("\n", $found['unknown']));
    }

    public function test_the_allowlists_name_only_pro_files(): void
    {
        $public = array_values(array_filter(array_keys(self::DYNAMIC_KEY_FILES), static fn (string $file): bool => ! Edition_Tree::is_pro_path($file)));
        $this->assertSame([], $public, 'DYNAMIC_KEY_FILES entries of public files belong in tests/free/Uninstall/DataRegistryGuardTest.');
        $this->assertSame([], array_values(array_intersect(DataRegistryGuardTest::declared(self::FOREIGN), DataRegistryGuardTest::declared(DataRegistryGuardTest::FOREIGN))), 'A foreign key is declared in both halves; keep it in the public one.');
    }

    public function test_every_wpmcp_table_is_registered(): void
    {
        $tables = DataRegistryGuardTest::tables(Edition_Tree::pro_sources());
        $owned  = array_values(array_unique(array_filter(array_column($tables, 'name'), [ DataRegistryGuardTest::class, 'looks_like_ours' ])));
        $this->assertSame([], array_values(array_diff($owned, Data_Registry::TABLES)), 'Tables created in src/ but missing from Data_Registry::TABLES.');

        $with_tables = array_unique(array_column(array_filter($tables, static fn ($t) => DataRegistryGuardTest::looks_like_ours($t['name'])), 'file'));
        $creators    = [];
        foreach (DataRegistryGuardTest::sources(Edition_Tree::pro_sources()) as $relative => $src) {
            if (preg_match('/\bdbDelta\s*\(/', $src)) {
                $creators[] = $relative;
            }
        }
        $this->assertSame([], array_values(array_diff($creators, $with_tables)), 'Files that run dbDelta() without a $wpdb->prefix . \'wpmcp_...\' table name the scan can see.');
    }

    public function test_every_wpmcp_directory_constant_is_registered_or_kept(): void
    {
        $found      = DataRegistryGuardTest::directory_constants(Edition_Tree::pro_sources());
        $registered = array_merge(Data_Registry::UPLOADS_DIRS, Data_Registry::CONTENT_DIRS);
        $missing    = array_diff(array_keys($found), $registered, array_keys(DataRegistryGuardTest::KEPT_DIRS));
        $this->assertSame([], array_values($missing), 'Directory constants named like WP MCP\'s that are neither in Data_Registry nor KEPT_DIRS.');
        $this->assertSame([], array_values(array_diff(DataRegistryGuardTest::PRO_OWNED_DIRS, array_keys($found))), 'Data_Registry lists directories no constant in src/ names any more.');
    }

    public function test_registry_entries_match_their_owners(): void
    {
        $this->assertContains(Compiled_Widget_Manifest::DIR_NAME, Data_Registry::CONTENT_DIRS);
        $this->assertNotContains(Compiled_Widget_Manifest::DIR_NAME, DataRegistryGuardTest::PRO_OWNED_DIRS);
    }
}
