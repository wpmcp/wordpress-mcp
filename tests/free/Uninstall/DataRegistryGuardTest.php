<?php

namespace WPMCP\Tests\Free\Uninstall;

use WPMCP\Data_Registry;
use WPMCP\Safety\File_Backup;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Support\Edition_Tree;
use WPMCP\Tools\Backup\Restore_Files;
use WPMCP\Tools\Backup\Site_Backup_Dir;
use WPMCP\Tools\Export\Content_Mirror;
use WPMCP\Tools\Export\Export_Dir;
use WPMCP\Tools\Filesystem\Filesystem_Guard;
use WPMCP\Tools\Redirects\Redirect_Store;
use WPMCP\Tools\Search\Search_Index_Store;
use WPMCP\Tools\Security\File_Quarantine;

/**
 * Guard for the data ownership registry (issue #480).
 *
 * With "delete all data" on, uninstall removes exactly what Data_Registry
 * lists, so a store missing from it would survive the plugin. This walks
 * every PHP file under src/ at token level and:
 *
 *  - resolves the key of every option, transient, site option and user meta
 *    write (a literal, a class constant, a concatenation that starts with
 *    one, or a local variable assigned from one), and fails when a key named
 *    like ours is not covered by the registry, or when a key named like
 *    anyone else's is not declared below as a foreign key the plugin edits
 *    on the site's behalf;
 *  - resolves every `$wpdb->prefix . 'name'` table and fails when a wpmcp
 *    table is not registered, or a file that runs dbDelta() creates a table
 *    the scan cannot see;
 *  - fails on any directory constant named like ours that is neither
 *    registered nor declared as kept.
 *
 * It also fails on registry entries nothing in src/ uses any more.
 *
 * This half scans the public sources (Edition_Tree::public_sources()) and
 * names Pro classes only by their values. Allowlist entries of Pro files
 * (DYNAMIC_KEY_FILES) and foreign keys only Pro code writes live in
 * tests/pro/Uninstall/DataRegistryGuardProTest, which reuses this scanner on
 * the Pro sources. Data_Registry is public code and also lists stores only
 * Pro code names; those are the PRO_OWNED_* lists here, which the Pro half
 * verifies.
 */
class DataRegistryGuardTest extends \WP_UnitTestCase
{
    /** Write functions => [zero-based key argument, kind]. */
    public const WRITERS = [
        'update_option'         => [ 0, 'option' ],
        'add_option'            => [ 0, 'option' ],
        'set_transient'         => [ 0, 'transient' ],
        'set_site_transient'    => [ 0, 'site_transient' ],
        'update_site_option'    => [ 0, 'site_option' ],
        'add_site_option'       => [ 0, 'site_option' ],
        'update_network_option' => [ 1, 'site_option' ],
        'add_network_option'    => [ 1, 'site_option' ],
        'update_user_meta'      => [ 1, 'user_meta' ],
        'add_user_meta'         => [ 1, 'user_meta' ],
        'update_user_option'    => [ 1, 'user_meta' ],
    ];

    /**
     * Keys owned by WordPress core or another plugin that a tool writes on
     * the site's behalf. Uninstall must never delete these. An entry ending
     * in '*' is a prefix. Keys only Pro code writes are declared in the Pro
     * half; a key both halves write is declared here.
     */
    public const FOREIGN = [
        'option'         => [
            // Core's active plugin list: a safe package update puts the
            // plugin it swapped back on it, without loading the new code
            // (issue #843).
            'active_plugins',
            'sidebars_widgets',
            'bricks_global_classes_timestamp',
            'bricks_global_classes_user',
            'astra-settings',
            'wpseo_taxonomy_meta',
            // Yoast SEO Premium's redirect list and its exports (issue #681).
            'wpseo-premium-redirects-*',
            'oxygen_vsb_css_files_state',
            'generateblocks_dynamic_css_posts',
            // Core's site identity and homepage settings, written by the
            // Core_Site_Pack ops on the theme pair (issue #713).
            'site_logo',
            'site_icon',
            'show_on_front',
            'page_on_front',
            'page_for_posts',
            // ElementsKit's widget and module lists and Elementor's Element
            // Manager list, switched together by the ElementsKit pack (issue #1012).
            'elementskit_options',
            'elementor_disabled_elements',
            // The SeedProd, Under Construction, WP Maintenance Mode,
            // Maintenance and CMP gate settings, toggled by the
            // Site_Gate_Pack ops on the theme pair (issue #903).
            'seedprod_settings',
            'ucp_options',
            'wpmm_settings',
            'mtnc-options',
            'niteoCS_status',
            'niteoCS_activation',
            // WPS Hide Login's login and redirect slugs, written by the
            // Login_Url_Pack ops on the theme pair (issue #896).
            'whl_page',
            'whl_redirect_admin',
            // LiteSpeed Cache's own settings, one option per id, written by
            // set-litespeed-settings on the theme pair (issue #933).
            'litespeed.conf.*',
            // Code Snippets' own recently deactivated list, put back by a
            // snippet activation's rollback (issue #576).
            'recently_active_snippets',
            // Post SMTP's settings, whose from address and name the
            // set-post-smtp-from op changes (issue #696).
            'postman_options',
            // GTranslate's settings, whose language lists the
            // gtranslate-set-languages op changes (issue #690).
            'GTranslate',
            // Max Mega Menu's global and per-location settings, changed by
            // the megamenu-update-location and -update-settings ops (#908).
            'megamenu_settings',
            // Easy Table of Contents' settings, whose placement keys the
            // easy-toc-update-settings op changes (issue #904).
            'ez-toc-settings',
            // Klaviyo's and Kit's settings, whose checkout signup and default
            // forms the email marketing ops change (issue #900).
            'klaviyo_settings',
            '_wp_convertkit_settings',
            // GTM4WP's settings and Microsoft Clarity's project id, changed
            // by gtm4wp-set-settings and clarity-set-project-id (issue #899).
            'gtm4wp-options',
            'clarity_project_id',
            // Custom Post Type UI's post types and taxonomies, written by
            // the cpt-create and cpt-update ops (issue #710).
            'cptui_post_types',
            'cptui_taxonomies',
            // Cookie Notice's settings, whose banner texts the
            // update-consent-banner-texts op changes (issue #686).
            'cookie_notice_options',
            // WPCode's global header, body and footer scripts, replaced by
            // the wpcode-set-header-footer op (issue #697).
            'ihaf_insert_header',
            'ihaf_insert_body',
            'ihaf_insert_footer',
            // FiboSearch's settings, changed by update-fibosearch-settings
            // on the theme pair (issue #700).
            'dgwt_wcas_settings',
            // Relevanssi's own settings, one option per name, changed by
            // update-relevanssi-settings on the theme pair (issue #700).
            'relevanssi_*',
            // FileBird's folder colours, keyed by folder id, which
            // filebird-delete-folder drops for the folders it deletes
            // (issue #906).
            'fbv_folder_colors',
            // Thrive Theme Builder's logo link, favicon and default featured
            // image, changed by update-thrive-theme-settings (issue #824).
            'thrive-theme-logo-url',
            'thrive-theme-favicon',
            'thrive-theme-default-featured-image',
            // Checkout Field Editor's checkout sections, whose fields the
            // update-checkout-fields op changes (issue #660).
            'wc_fields_*',
            // SearchWP's engines config, which the SearchWP ops change
            // (issue #993).
            'searchwp_engines',
            // Cart Abandonment Recovery's abandoned and lost cart timing,
            // which cartabandonment-update-timing changes (issue #1096).
            'wcf_ca_cron_run_time',
            'wcf_ca_cart_lost_time',
        ],
        'transient'      => [
            'scrape_key_*',
            // Custom Post Type UI's own flush-on-next-admin-load flag, set
            // after a cpt-create or cpt-update op the way its save sets it
            // (issue #710).
            'cptui_flush_rewrite_rules',
        ],
        'site_transient' => [],
        'site_option'    => [
            // Core's network plugin list, put back by a network plugin
            // write's rollback (issue #557).
            'active_sitewide_plugins',
        ],
        'user_meta'      => [
            // LearnDash's own learner progress and quiz attempts, written by
            // the LMS pack's LearnDash learner ops (issue #575).
            '_sfwd-course_progress',
            '_sfwd-quizzes',
            // Awesome Support's per-agent open ticket counter, put back by
            // a ticket close's rollback (issue #708).
            'wpas_open_tickets',
        ],
    ];

    /**
     * Files whose writes take a key the code cannot know statically, with
     * the reason. Every one of them writes a key the site already has (a
     * tool editing a named setting, a rollback putting back a recorded
     * value) or one built by a helper whose prefix is pinned by a test of
     * its own class.
     */
    public const DYNAMIC_KEY_FILES = [
        'src/Cron_Registry.php'                              => 'job store names from Cron_Registry::JOB_HOOKS, all wpmcp_',
        'src/Modules/Cmb2/Adapter/Wp_Cmb2_Values.php' => 'puts back a CMB2 options page\'s own option array (the box\'s option_key) after CMB2 rejected a value (issue #1089)',
        'src/Modules/Site_Gate_Settings/Adapter/Wp_Plan_Writer.php' => 'writes the gate plugin options its stores plan (ucp_options, wpmm_settings, mtnc-options, niteoCS_*), never a wpmcp_ key',
        'src/Auth/Atomic_Option.php'                         => 'creates whichever wpmcp_ option its caller locks',
        'src/Auth/Client_Metadata_Document.php'              => 'cache key built by cache_key() from CACHE_PREFIX (wpmcp_oauth_cimd_)',
        'src/Cloud/Cloud_Credentials.php'                    => 'restores its own wpmcp_cloud_ options from a snapshot',
        'src/Tools/Settings/Update_Settings.php'             => 'edits a named core setting',
        'src/Integrations/PixelYourSite_Store.php'           => 'writes the legacy option of one network (pys_facebook, pys_ga or pys_gtm), named by PixelYourSite_Network::row(); PixelYourSite\'s own data, never WP MCP\'s',
        'src/Integrations/Site_Gate_Pack.php'                => 'puts back the one gate option (seedprod_settings or ucp_options) it just wrote, named by WRITABLE; both are FOREIGN',
        'src/Tools/Meta/Update_Option.php'                   => 'edits a named option on request',
        'src/Tools/CustomCode/Custom_Code_Store.php'         => 'per-post option built from POST_OPTION_PREFIX (wpmcp_custom_code_post_)',
        'src/Tools/Packages/Manage_Updates.php'              => 'core auto-update lists',
        'src/Tools/Packages/Safe_Package_Update.php'         => 'puts back the options a rolled-back package update changed (issue #843)',
        'src/Tools/Sync/Change_Set_Applier.php'              => 'applies a change set to named options',
        'src/Tools/Backup/Restore_Session.php'               => 'puts back the restoring user\'s own session and meta',
        'src/Tools/Builders/Avada_Cache.php'                 => 'Avada cache options',
        'src/Tools/Media/Upload_Grant.php'                   => 'grant option built from OPTION_PREFIX (wpmcp_upload_grant_)',
        'src/Admin/Connection_Page.php'                      => 'one-time create request transient, an hour long, built from REQUEST_PREFIX (wpmcp_conn_req_)',
        'src/Tools/Diagnostics/Mail_Check.php'               => 'rate-limit key built from LIMIT_KEY (wpmcp_mail_test_)',
        'src/Privacy/Personal_Data.php'                      => 'cursor and address keys built from CURSOR_PREFIX (wpmcp_privacy_cursor_) and IPS_PREFIX (wpmcp_privacy_ips_)',
        'src/Safety/Rollback_Service.php'                    => 'puts back recorded options and user meta',
        'src/Safety/Wc_Shipping_Zone_Snapshot.php'           => 'puts back recorded WooCommerce options',
        'src/Safety/BbPress_Posts_Snapshot.php'              => 'puts back the recorded bbPress super sticky list (_bbp_super_sticky_topics)',
        'src/Integrations/Theme_Settings_Pack.php'           => 'theme settings options',
        'src/Integrations/Elementor_Addon_Packs.php'         => 'Elementor add-on settings',
        'src/Integrations/LMS_LearnDash.php'                 => 'LearnDash\'s own enrollment user meta (course_<id>_access_from)',
        'src/Integrations/Cache_Settings_Adapter.php'        => 'the cache plugin\'s own settings option (wp_rocket_settings, perfmatters_options, FLYING_PRESS_CONFIG)',
    ];

    /**
     * Directory constants named like ours that uninstall deliberately keeps,
     * with the reason.
     */
    public const KEPT_DIRS = [
        'wpmcp-skills' => 'skills the site owner wrote by hand in wp-content; WP MCP only reads them',
    ];

    /**
     * Sort scanned writes into what the guard reports.
     *
     * @param array<int, array<string, mixed>> $writes        from writes()
     * @param array<string, string>            $dynamic_files file => reason
     * @param array<string, string[]>          $foreign       kind => entries
     * @return array{dynamic_stale: string[], unresolved: string[], missing: string[], unknown: string[], foreign_used: array<string, true>}
     */
    public static function key_problems(array $writes, array $dynamic_files, array $foreign): array
    {
        $unresolved = [];
        $missing    = [];
        $unknown    = [];
        $used       = [];
        foreach ($writes as $write) {
            $where = sprintf('%s:%d %s(%s)', $write['file'], $write['line'], $write['function'], $write['expr']);
            if (null === $write['key']) {
                if (! isset($dynamic_files[ $write['file'] ])) {
                    $unresolved[] = $where;
                }
                continue;
            }

            [ $key, $exact ] = $write['key'];
            if (self::looks_like_ours($key)) {
                if (! self::covered($write['kind'], $key, $exact)) {
                    $missing[] = $where . ' => ' . $key . ($exact ? '' : '*');
                }
                continue;
            }
            $entry = self::foreign($write['kind'], $key, $foreign);
            if (null === $entry) {
                $unknown[] = $where . ' => ' . $key . ($exact ? '' : '*');
            } else {
                $used[ $write['kind'] . ':' . $entry ] = true;
            }
        }

        $dynamic = array_unique(array_column(array_filter($writes, static fn ($w) => null === $w['key']), 'file'));
        return [
            'dynamic_stale' => array_values(array_diff(array_keys($dynamic_files), $dynamic)),
            'unresolved'    => $unresolved,
            'missing'       => $missing,
            'unknown'       => $unknown,
            'foreign_used'  => $used,
        ];
    }

    /**
     * @param array<string, string[]> $foreign kind => entries
     * @return string[] "kind:entry"
     */
    public static function declared(array $foreign): array
    {
        $declared = [];
        foreach ($foreign as $kind => $entries) {
            foreach ($entries as $entry) {
                $declared[] = $kind . ':' . $entry;
            }
        }
        return $declared;
    }

    /**
     * Data_Registry directories only a Pro constant names; the Pro half finds
     * the constant. Empty: the compiled widget store
     * (Compiled_Widget_Manifest::DIR_NAME) is display code and stays public,
     * so a site keeps its compiled widgets after a downgrade.
     */
    public const PRO_OWNED_DIRS = [];

    public function test_every_written_key_is_registered_or_declared_foreign(): void
    {
        $writes = self::writes();
        $this->assertGreaterThan(50, count($writes), 'The scan found too few writes, so it is not looking in the right place.');

        $found = self::key_problems($writes, self::DYNAMIC_KEY_FILES, self::FOREIGN);
        $this->assertSame([], $found['dynamic_stale'], 'DYNAMIC_KEY_FILES lists files with no dynamic write any more.');
        $this->assertSame([], $found['unresolved'], "Writes whose key could not be resolved; resolve it to a constant or add the file to DYNAMIC_KEY_FILES with the reason:\n" . implode("\n", $found['unresolved']));
        $this->assertSame([], $found['missing'], "Keys WP MCP writes that Data_Registry does not cover, so deleting the plugin would leave them behind:\n" . implode("\n", $found['missing']));
        $this->assertSame([], array_values(array_diff(self::declared(self::FOREIGN), array_keys($found['foreign_used']))), 'FOREIGN lists keys nothing in src/ writes any more.');
        $this->assertSame([], $found['unknown'], "Keys written that are neither WP MCP's (wpmcp_ / _wpmcp_) nor declared FOREIGN here. A new store must use a registered prefix:\n" . implode("\n", $found['unknown']));
    }

    public function test_the_allowlists_name_only_public_files(): void
    {
        $pro = array_values(array_filter(array_keys(self::DYNAMIC_KEY_FILES), [ Edition_Tree::class, 'is_pro_path' ]));
        $this->assertSame([], $pro, 'DYNAMIC_KEY_FILES entries of Pro files belong in tests/pro/Uninstall/DataRegistryGuardProTest.');
    }

    public function test_every_wpmcp_table_is_registered(): void
    {
        $tables = self::tables();
        $owned  = array_values(array_unique(array_filter(array_column($tables, 'name'), [self::class, 'looks_like_ours'])));

        $this->assertNotEmpty($owned, 'The scan found no WP MCP tables at all.');
        $this->assertSame([], array_values(array_diff($owned, Data_Registry::TABLES)), 'Tables created in src/ but missing from Data_Registry::TABLES.');
        $this->assertSame([], array_values(array_diff(Data_Registry::TABLES, $owned)), 'Data_Registry::TABLES lists tables nothing in src/ uses any more.');

        $with_tables = array_unique(array_column(array_filter($tables, static fn ($t) => self::looks_like_ours($t['name'])), 'file'));
        $creators    = [];
        foreach (self::sources() as $relative => $src) {
            if (preg_match('/\bdbDelta\s*\(/', $src) && 'src/Tools/Backup/Sql_Importer.php' !== $relative) {
                $creators[] = $relative;
            }
        }
        $this->assertNotEmpty($creators);
        $this->assertSame([], array_values(array_diff($creators, $with_tables)), 'Files that run dbDelta() without a $wpdb->prefix . \'wpmcp_...\' table name the scan can see.');
    }

    /**
     * @param array<string, string>|null $files src/... path => absolute path; the public sources when null
     * @return array<string, string> directory name => file whose constant names it
     */
    public static function directory_constants(?array $files = null): array
    {
        $found = [];
        foreach (self::sources($files) as $relative => $src) {
            if (preg_match_all("/const\s+\w*DIR\w*\s*=\s*'(\.?wpmcp[-_][^'\/]*)'/", $src, $m)) {
                foreach ($m[1] as $name) {
                    $found[ $name ] = $relative;
                }
            }
        }
        return $found;
    }

    public function test_every_wpmcp_directory_constant_is_registered_or_kept(): void
    {
        $found = self::directory_constants();
        $this->assertNotEmpty($found);

        $registered = array_merge(Data_Registry::UPLOADS_DIRS, Data_Registry::CONTENT_DIRS);
        $missing    = array_diff(array_keys($found), $registered, array_keys(self::KEPT_DIRS));
        $this->assertSame([], array_values($missing), 'Directory constants named like WP MCP\'s that are neither in Data_Registry nor KEPT_DIRS.');
        $this->assertSame([], array_values(array_diff($registered, array_keys($found), self::PRO_OWNED_DIRS)), 'Data_Registry lists directories no constant in src/ names any more.');
        $this->assertSame([], array_values(array_diff(self::PRO_OWNED_DIRS, $registered)), 'PRO_OWNED_DIRS lists directories Data_Registry does not.');
        $this->assertSame([], array_values(array_intersect(self::PRO_OWNED_DIRS, array_keys($found))), 'PRO_OWNED_DIRS lists directories a public constant names; drop them from the list.');
    }

    /**
     * The registry names stores by string so it can load in uninstall.php
     * without an autoloader and survive builds that prune classes. Pin each
     * string to the owning class's constant.
     */
    public function test_registry_entries_match_their_owners(): void
    {
        global $wpdb;

        $this->assertSame(
            [ Snapshot_Store::table_name(), Redirect_Store::table_name(), Search_Index_Store::table_name() ],
            array_map(static fn ($t) => $wpdb->prefix . $t, Data_Registry::TABLES)
        );
        $this->assertSame(
            [ File_Backup::BACKUP_DIR, Site_Backup_Dir::DIR_NAME, Export_Dir::DIR_NAME, Content_Mirror::DIR_NAME, Filesystem_Guard::BACKUP_DIR, File_Quarantine::DIR ],
            Data_Registry::UPLOADS_DIRS
        );
        $this->assertSame(
            // Compiled_Widget_Manifest::DIR_NAME by value (a Pro class; the Pro half pins it).
            [ Restore_Files::DIR_NAME, 'wpmcp-widgets' ],
            Data_Registry::CONTENT_DIRS
        );
    }

    public function test_registry_covers_transients_and_both_meta_spellings(): void
    {
        foreach ([ 'wpmcp_oauth_tokens', '_transient_wpmcp_rl_x', '_transient_timeout_wpmcp_rl_x', '_site_transient_wpmcp_x', '_site_transient_timeout_wpmcp_x' ] as $option) {
            $this->assertTrue(Data_Registry::owns_option($option), $option);
        }
        foreach ([ 'wpmcp_dismissed_announcements', '_wpmcp_chat_anthropic_key' ] as $meta) {
            $this->assertTrue(Data_Registry::owns_user_meta($meta), $meta);
        }
        foreach ([ 'wpmcp_x', '_site_transient_wpmcp_x', '_site_transient_timeout_wpmcp_x' ] as $meta) {
            $this->assertTrue(Data_Registry::owns_site_meta($meta), $meta);
        }
        foreach ([ 'wpmcpx', 'other_wpmcp_x', '_transient_other', 'fs_accounts', 'active_plugins', '' ] as $foreign) {
            $this->assertFalse(Data_Registry::owns_option($foreign), $foreign);
            $this->assertFalse(Data_Registry::owns_user_meta($foreign), $foreign);
            $this->assertFalse(Data_Registry::owns_site_meta($foreign), $foreign);
        }
    }

    public function test_the_scanner_resolves_every_key_shape(): void
    {
        $src = "<?php\nnamespace WPMCP\\Auth;\nclass Demo { const OPTION = 'wpmcp_demo'; const PREFIX = 'wpmcp_p_';\n"
            . "function a() { update_option('wpmcp_literal', 1); }\n"
            . "function b() { \\set_transient(self::PREFIX . \$id, 1); }\n"
            . "function c() { \$key = 'wpmcp_var_' . md5('x'); set_transient(\$key, 1); }\n"
            . "function d() { update_user_meta(\$id, Demo::OPTION, 1); }\n"
            . "function e() { update_option(\$name, 1); }\n"
            . "function f() { update_option(\"wpmcp_enc_{\$x}\", 1); }\n"
            . "function g() { \$this->update_option('not_a_call'); }\n}\n";

        $writes = self::writes_in('src/Demo.php', $src);

        $this->assertSame(
            [ [ 'wpmcp_literal', true ], [ 'wpmcp_p_', false ], [ 'wpmcp_var_', false ], [ 'wpmcp_demo', true ], null, [ 'wpmcp_enc_', false ] ],
            array_column($writes, 'key')
        );
        $this->assertSame([ 'option', 'transient', 'transient', 'user_meta', 'option', 'option' ], array_column($writes, 'kind'));
    }

    public static function looks_like_ours(string $key): bool
    {
        return 1 === preg_match('/^_?wpmcp/i', $key);
    }

    /** Whether the registry deletes $key (or every key starting with it). */
    public static function covered(string $kind, string $key, bool $exact): bool
    {
        $check = static function (string $name) use ($kind): bool {
            switch ($kind) {
                case 'option':
                    return Data_Registry::owns_option($name);
                case 'transient':
                    return Data_Registry::owns_option('_transient_' . $name) && Data_Registry::owns_option('_transient_timeout_' . $name);
                case 'site_transient':
                    return Data_Registry::owns_option('_site_transient_' . $name) && Data_Registry::owns_site_meta('_site_transient_' . $name);
                case 'site_option':
                    return Data_Registry::owns_option($name) && Data_Registry::owns_site_meta($name);
                default:
                    return Data_Registry::owns_user_meta($name);
            }
        };

        // A prefix is covered only when the registry owns everything that
        // can follow it, which is the case when it owns the bare prefix.
        return $exact ? $check($key) : $check($key) && $check($key . 'x');
    }

    /**
     * The FOREIGN entry that declares $key, or null.
     *
     * @param array<string, string[]>|null $foreign kind => entries; FOREIGN when null
     */
    public static function foreign(string $kind, string $key, ?array $foreign = null): ?string
    {
        foreach (($foreign ?? self::FOREIGN)[ $kind ] ?? [] as $entry) {
            if (str_ends_with($entry, '*') ? str_starts_with($key, substr($entry, 0, -1)) : $entry === $key) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * @param array<string, string>|null $files src/... path => absolute path (Edition_Tree); the public sources when null
     * @return array<string, string> src/... path => source
     */
    public static function sources(?array $files = null): array
    {
        $out = [];
        foreach ($files ?? Edition_Tree::public_sources() as $relative => $absolute) {
            $out[ $relative ] = (string) file_get_contents($absolute);
        }
        ksort($out);
        return $out;
    }

    /** @param array<string, string>|null $files src/... path => absolute path; the public sources when null */
    public static function writes(?array $files = null): array
    {
        $writes = [];
        foreach (self::sources($files) as $relative => $src) {
            $writes = array_merge($writes, self::writes_in($relative, $src));
        }
        return $writes;
    }

    /**
     * @param array<string, string>|null $files src/... path => absolute path; the public sources when null
     * @return array<int, array{name:string,file:string}>
     */
    public static function tables(?array $files = null): array
    {
        $tables = [];
        foreach (self::sources($files) as $relative => $src) {
            $ctx    = self::context($src);
            $tokens = self::tokens($src);
            foreach ($tokens as $i => $t) {
                self::track($ctx, $tokens, $i);
                if (! is_array($t) || T_VARIABLE !== $t[0] || '$wpdb' !== $t[1]) {
                    continue;
                }
                $prop = $tokens[ $i + 2 ] ?? null;
                if (! is_array($tokens[ $i + 1 ] ?? null) || T_OBJECT_OPERATOR !== $tokens[ $i + 1 ][0]
                    || ! is_array($prop) || ! in_array($prop[1], [ 'prefix', 'base_prefix' ], true)
                    || '.' !== ($tokens[ $i + 3 ] ?? null)) {
                    continue;
                }
                $resolved = self::resolve_operand(array_slice($tokens, $i + 4, 3), $ctx);
                if (null !== $resolved) {
                    $tables[] = [ 'name' => $resolved, 'file' => $relative ];
                }
            }
        }
        return $tables;
    }

    /** Parse state, seeded with the file's own string class constants (private ones included). */
    private static function context(string $src): array
    {
        preg_match_all("/const\\s+(\\w+)\\s*=\\s*'([^'\\\\]*)'\\s*;/", $src, $m, PREG_SET_ORDER);
        $consts = [];
        foreach ($m as $match) {
            $consts[ $match[1] ] = $match[2];
        }
        return [ 'namespace' => '', 'class' => '', 'uses' => [], 'function_start' => 0, 'consts' => $consts ];
    }

    private static function tokens(string $src): array
    {
        return array_values(array_filter(
            token_get_all($src),
            static fn ($t) => ! is_array($t) || ! in_array($t[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true)
        ));
    }

    /** Keep namespace, class, imports and the current function's start up to date. */
    private static function track(array &$ctx, array $tokens, int $i): void
    {
        $t = $tokens[ $i ];
        if (! is_array($t)) {
            return;
        }
        $next = $tokens[ $i + 1 ] ?? null;
        if (T_NAMESPACE === $t[0] && is_array($next)) {
            $ctx['namespace'] = $next[1];
        } elseif (T_USE === $t[0] && '' === $ctx['class'] && is_array($next)) {
            $name = ltrim($next[1], '\\');
            $as   = $tokens[ $i + 2 ] ?? null;
            $ctx['uses'][ (is_array($as) && T_AS === $as[0]) ? $tokens[ $i + 3 ][1] : substr((string) strrchr('\\' . $name, '\\'), 1) ] = $name;
        } elseif (T_CLASS === $t[0] && is_array($next) && T_STRING === $next[0]) {
            $ctx['class'] = ltrim($ctx['namespace'] . '\\' . $next[1], '\\');
        } elseif (T_FUNCTION === $t[0] || T_FN === $t[0]) {
            $ctx['function_start'] = $i;
        }
    }

    /** @return array<int, array{file:string,line:int,function:string,kind:string,key:?array,expr:string}> */
    public static function writes_in(string $relative, string $src): array
    {
        $tokens = self::tokens($src);
        $ctx    = self::context($src);
        $writes = [];
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            self::track($ctx, $tokens, $i);
            $t = $tokens[ $i ];
            if (! is_array($t) || ! in_array($t[0], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true)) {
                continue;
            }
            $function = strtolower(ltrim($t[1], '\\'));
            if (! isset(self::WRITERS[ $function ]) || '(' !== ($tokens[ $i + 1 ] ?? null)) {
                continue;
            }
            $prev = $tokens[ $i - 1 ] ?? null;
            if (is_array($prev) && in_array($prev[0], [ T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW ], true)) {
                continue;
            }

            [ $position, $kind ] = self::WRITERS[ $function ];
            $arg      = self::arguments($tokens, $i + 1)[ $position ] ?? [];
            $writes[] = [
                'file'     => $relative,
                'line'     => $t[2],
                'function' => $function,
                'kind'     => $kind,
                'key'      => self::resolve_key($arg, $ctx, $tokens, $i, 0),
                'expr'     => implode('', array_map(static fn ($x) => is_array($x) ? $x[1] : $x, $arg)),
            ];
        }

        return $writes;
    }

    /** The argument token lists of the call whose '(' is at $open. */
    private static function arguments(array $tokens, int $open): array
    {
        $args  = [ [] ];
        $depth = 0;
        for ($i = $open; $i < count($tokens); $i++) {
            $t = $tokens[ $i ];
            if (in_array($t, [ '(', '[', '{' ], true) || (is_array($t) && in_array($t[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true))) {
                if (++$depth === 1) {
                    continue;
                }
            } elseif (in_array($t, [ ')', ']', '}' ], true)) {
                if (--$depth === 0) {
                    break;
                }
            } elseif (',' === $t && 1 === $depth) {
                $args[] = [];
                continue;
            }
            $args[ count($args) - 1 ][] = $t;
        }
        return $args;
    }

    /**
     * [key, exact] for an argument, or null when it is dynamic. A
     * concatenation resolves to its leading operand as a prefix; a local
     * variable resolves through its last assignment in the same function.
     *
     * @return array{0:string,1:bool}|null
     */
    private static function resolve_key(array $arg, array $ctx, array $tokens, int $at, int $depth): ?array
    {
        if ([] === $arg) {
            return null;
        }

        // "wpmcp_x_{$id}": the leading literal part is a prefix.
        if ('"' === $arg[0]) {
            $first = $arg[1] ?? null;
            return is_array($first) && T_ENCAPSED_AND_WHITESPACE === $first[0] ? [ $first[1], false ] : null;
        }

        if (is_array($arg[0]) && T_VARIABLE === $arg[0][0] && $depth < 2) {
            $resolved = self::resolve_variable($arg[0][1], $ctx, $tokens, $at, $depth);
            if (null === $resolved) {
                return null;
            }
            return 1 === count($arg) ? $resolved : [ $resolved[0], false ];
        }

        $operand = is_array($arg[0]) && T_CONSTANT_ENCAPSED_STRING === $arg[0][0] ? array_slice($arg, 0, 1) : array_slice($arg, 0, 3);
        $value   = self::resolve_operand($operand, $ctx);
        if (null === $value) {
            return null;
        }
        $rest = array_slice($arg, count($operand));
        if ([] === $rest) {
            return [ $value, true ];
        }
        return '.' === $rest[0] ? [ $value, false ] : null;
    }

    private static function resolve_variable(string $name, array $ctx, array $tokens, int $at, int $depth): ?array
    {
        for ($i = $at - 1; $i > $ctx['function_start']; $i--) {
            $t = $tokens[ $i ];
            if (is_array($t) && T_VARIABLE === $t[0] && $name === $t[1] && '=' === ($tokens[ $i + 1 ] ?? null)) {
                $rhs = [];
                for ($j = $i + 2; $j < $at && ';' !== $tokens[ $j ]; $j++) {
                    $rhs[] = $tokens[ $j ];
                }
                return self::resolve_key($rhs, $ctx, $tokens, $i, $depth + 1);
            }
        }
        return null;
    }

    /** A string literal or Class::CONST, resolved; null for anything else. */
    private static function resolve_operand(array $operand, array $ctx): ?string
    {
        if (1 <= count($operand) && is_array($operand[0]) && T_CONSTANT_ENCAPSED_STRING === $operand[0][0]) {
            return stripslashes(substr($operand[0][1], 1, -1));
        }
        if (3 !== count($operand) || ! is_array($operand[0]) || ! is_array($operand[1]) || T_DOUBLE_COLON !== $operand[1][0] || ! is_array($operand[2])) {
            return null;
        }

        $name = $operand[0][1];
        if (in_array(strtolower($name), [ 'self', 'static' ], true)) {
            $fqn = $ctx['class'];
        } elseif ('\\' === $name[0]) {
            $fqn = ltrim($name, '\\');
        } else {
            $first = explode('\\', $name)[0];
            $fqn   = isset($ctx['uses'][ $first ])
                ? $ctx['uses'][ $first ] . substr($name, strlen($first))
                : ltrim($ctx['namespace'] . '\\' . $name, '\\');
        }

        // The file's own constants first: private ones cannot be read with
        // constant() from here, and the scanner self-test's class is never loaded.
        if ($fqn === $ctx['class'] && isset($ctx['consts'][ $operand[2][1] ])) {
            return $ctx['consts'][ $operand[2][1] ];
        }

        $constant = $fqn . '::' . $operand[2][1];
        if (! defined($constant)) {
            return null;
        }
        try {
            $value = constant($constant);
        } catch (\Error $e) {
            return null;
        }
        return is_string($value) ? $value : null;
    }
}
