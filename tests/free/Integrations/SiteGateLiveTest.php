<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Site_Gate_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Maintenance\Site_Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #903: the site gate reader and toggles against the real coming soon
 * and maintenance plugins, all free on wordpress.org.
 *
 * It runs only in the local gate's live site gate leg (bin/test-local.sh
 * --live-site-gate), which sets WPMCP_LIVE_SITE_GATE=1, installs the five
 * plugins and runs PHPUnit once per plugin with WPMCP_SITE_GATE_PLUGIN naming
 * the one loaded (they all claim the front door, so never together). Each run
 * checks that what the reader expects is what that plugin really stores and
 * decides: its own defaults carry the keys read, and its own code agrees
 * with the state the reader reports and the toggles write.
 *
 * Everywhere else the plugins are absent and the test is skipped; the live
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group site-gate-live
 */
class SiteGateLiveTest extends \WP_UnitTestCase
{
    private string $slug = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->slug = (string) getenv('WPMCP_SITE_GATE_PLUGIN');
        if ('' === $this->slug || ! getenv('WPMCP_LIVE_SITE_GATE')) {
            $this->markTestSkipped('Needs a real gate plugin (bin/test-local.sh --live-site-gate).');
        }
        Snapshot_Store::install();
        update_option('active_plugins', [ Site_Gate::PLUGINS[ $this->slug ] ]);
        add_filter('pre_http_request', static fn () => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => '' ], 'cookies' => [], 'filename' => null ], 1);
        add_filter(Site_Gate_Pack::ENABLE_FILTER, '__return_true');
        // The kernel keeps a module's operation definitions for the request
        // (one PHP process here), opt-in state included: read them again.
        \WPMCP\Plugin::instance()->kernel()->use_registry_for_tests(null);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        remove_all_filters(Site_Gate_Pack::ENABLE_FILTER);
        \WPMCP\Plugin::instance()->kernel()->use_registry_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function gate(): array
    {
        $out = Site_Gate::plugin_gate($this->slug);
        $this->assertIsArray($out);
        return $out;
    }

    private function toggle(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Theme_Integration())->handle_write($call);
    }

    public function test_the_loaded_plugin_is_read_and_agrees_with_its_own_code(): void
    {
        $method = 'check_' . str_replace('-', '_', $this->slug);
        $this->$method();
        $status = Site_Gate::status(false);
        $this->assertContains(Site_Gate::source_for($this->slug), array_column($status['gates'], 'source'), 'an active plugin is listed');
    }

    /**
     * Issue #1099: the settings ops write what the loaded plugin reads, and
     * their undo leaves it exactly as its own code stored it.
     */
    public function test_the_settings_ops_write_what_the_plugin_reads(): void
    {
        $method = 'settings_' . str_replace('-', '_', $this->slug);
        $this->$method();
    }

    private function settings(string $op, array $args): array
    {
        $out = $this->toggle($op, [ 'plugin' => $this->slug ] + $args, 'set-site-gate-bypass' === $op);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function settings_read(): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => 'get-site-gate-settings', 'args' => [] ]);
    }

    private function settings_under_construction_page(): void
    {
        update_option('ucp_options', \UCP::default_options());
        $before = get_option('ucp_options');
        $out    = $this->settings('set-site-gate-content', [ 'headline' => 'Live heading', 'message' => '<p>Live text</p>' ]);
        $this->assertSame('Live heading', \UCP::get_options()['heading1'], 'the plugin reads the heading written');
        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame($before, get_option('ucp_options'));

        $this->toggle('enable-site-gate', [ 'plugin' => $this->slug ], true);
        $this->assertSame('admin_not_bypassed', $this->toggle('set-site-gate-bypass', [ 'roles' => [ 'editor' ] ], true)['error']['code'] ?? '');
        $this->settings('set-site-gate-bypass', [ 'roles' => [ 'administrator', 'editor' ] ]);
        $this->assertFalse(\UCP::is_construction_mode_enabled(false), 'the administrator still walks past it');
    }

    private function settings_wp_maintenance_mode(): void
    {
        $wpmm = \WP_Maintenance_Mode::get_instance();
        update_option('wpmm_settings', $wpmm->default_settings());
        delete_option('wpmm_new_look');
        $out = $this->settings('set-site-gate-content', [ 'headline' => 'Live heading', 'countdown' => gmdate('c', time() + DAY_IN_SECONDS + 600) ]);
        $settings = new \ReflectionProperty(\WP_Maintenance_Mode::class, 'plugin_settings');
        $settings->setValue($wpmm, get_option('wpmm_settings'));
        $this->assertSame('Live heading', get_option('wpmm_settings')['design']['heading']);
        $this->assertEqualsWithDelta(DAY_IN_SECONDS + 600, $wpmm->calculate_backtime(), 120, 'the plugin computes the countdown written');
        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);

        $this->settings('set-site-gate-bypass', [ 'ip_allowlist' => [ '203.0.113.5' ] ]);
        $settings->setValue($wpmm, get_option('wpmm_settings'));
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $this->assertTrue($wpmm->check_exclude(), 'the plugin lets the listed address through');
        $_SERVER['REMOTE_ADDR'] = '198.51.100.1';
        $this->assertFalse($wpmm->check_exclude());
        $this->assertStringNotContainsString('203.0.113.5', (string) wp_json_encode($this->settings_read()));
    }

    private function settings_maintenance(): void
    {
        $load = new \ReflectionMethod(\MTNC::class, 'load_options');
        $load->invoke(\MTNC::getInstance());
        $before = get_option('mtnc-options');
        $this->assertIsObject($before['options']['themes'][ $before['options']['theme_global'] ], 'the plugin stores its theme as an object');

        $out   = $this->settings('set-site-gate-content', [ 'headline' => 'Live heading', 'message' => '<p>Live text</p>' ]);
        $read  = $this->settings_read()['result']['content'] ?? [];
        $this->assertSame('Live heading', $read['headline'] ?? null, 'the plugin\'s own default design has a header module');
        $this->assertSame('<p>Live text</p>', $read['message'] ?? null);
        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertEquals($before, get_option('mtnc-options'), 'undo puts the plugin\'s theme objects back');
        $this->assertIsObject($load->invoke(\MTNC::getInstance())['options']['themes'][ $before['options']['theme_global'] ]);

        $this->settings('set-site-gate-bypass', [ 'ip_allowlist' => [ '203.0.113.5' ], 'bypass_link' => 'liveLink1234' ]);
        $loaded = $load->invoke(\MTNC::getInstance())['options'];
        $this->assertSame('203.0.113.5', $loaded['ip_whitelist']);
        $this->assertSame('liveLink1234', $loaded['direct_access_link']);
    }

    private function settings_cmp_coming_soon_maintenance(): void
    {
        $cmp = \CMP_Coming_Soon_and_Maintenance::instance();
        $this->settings('set-site-gate-content', [ 'headline' => 'Live heading', 'countdown' => gmdate('c', time() + DAY_IN_SECONDS) ]);
        $this->assertSame('Live heading', get_option('niteoCS_body_title'));

        update_option('niteoCS_status', '1');
        $this->act_as('editor');
        update_option('niteoCS_roles', wp_json_encode([ 'editor' ]));
        $this->assertTrue($cmp->cmp_roles_filter());
        $this->assertSame('admin_not_bypassed', $this->toggle('set-site-gate-bypass', [ 'roles' => [ 'author' ] ], true)['error']['code'] ?? '', 'an editor with manage_options would lock themself out');
        $this->settings('set-site-gate-bypass', [ 'roles' => [ 'editor', 'author' ], 'bypass_link' => 'liveLink1234' ]);
        $this->assertTrue($cmp->cmp_roles_filter(), 'the plugin still lets the editor through');
        $this->assertSame('liveLink1234', get_option('niteoCS_bypass_id'));
    }

    private function settings_coming_soon(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Soon', 'post_content_filtered' => wp_json_encode([ 'access_by_role' => [], 'document' => [ 'sections' => [] ] ]) ]);
        update_option('seedprod_coming_soon_page_id', $page);
        update_option('seedprod_settings', wp_json_encode([ 'enable_coming_soon_mode' => true ]));

        $this->assertSame('seedprod_builder_content', $this->toggle('set-site-gate-content', [ 'headline' => 'x' ])['error']['code'] ?? '');
        $this->assertSame('admin_not_bypassed', $this->toggle('set-site-gate-bypass', [ 'roles' => [ 'editor' ] ], true)['error']['code'] ?? '');
        $out = $this->settings('set-site-gate-bypass', [ 'roles' => [ 'administrator' ] ]);
        $this->assertSame([ 'administrator' ], json_decode(get_post($page)->post_content_filtered, true)['access_by_role']);
        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        clean_post_cache($page);
        $this->assertSame([], json_decode(get_post($page)->post_content_filtered, true)['access_by_role']);
    }

    /** An acting user with this role plus manage_options, so the theme pair lets them write. */
    private function act_as(string $role): void
    {
        $id = self::factory()->user->create([ 'role' => $role ]);
        get_userdata($id)->add_cap('manage_options');
        get_userdata($id)->add_cap('edit_theme_options');
        wp_set_current_user($id);
    }

    private function check_wp_maintenance_mode(): void
    {
        $this->assertTrue(class_exists('WP_Maintenance_Mode'));
        $defaults = \WP_Maintenance_Mode::get_instance()->default_settings();
        foreach ([ 'status', 'backend_role', 'frontend_role', 'exclude', 'bypass_bots' ] as $key) {
            $this->assertArrayHasKey($key, $defaults['general'], "the reader relies on general.{$key}");
        }
        $this->assertSame(0, $defaults['general']['status']);
        $this->assertFalse($this->gate()['active']);

        $defaults['general']['status']        = 1;
        $defaults['general']['frontend_role'] = [ 'editor' ];
        update_option('wpmm_settings', $defaults);

        $gate = $this->gate();
        $this->assertTrue($gate['active']);
        $this->assertSame(503, $gate['http_status']);
        $this->assertSame([ 'editor' ], $gate['bypass']['roles']);
        $this->assertTrue(Site_Gate::status(false)['public_site_gated']);

        // The toggle, from the plugin's own defaults: it writes the integer
        // status the plugin compares with === 1, and the plugin lets the
        // administrator who turned it on through.
        $defaults['general']['status']        = 0;
        $defaults['general']['frontend_role'] = [];
        update_option('wpmm_settings', $defaults);
        update_option('wpmm_version', \WP_Maintenance_Mode::VERSION);
        $out = $this->toggle('enable-site-gate', [ 'plugin' => 'wp-maintenance-mode' ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame(1, get_option('wpmm_settings')['general']['status']);
        $this->assertTrue($this->gate()['active']);
        $this->assertTrue(\WP_Maintenance_Mode::get_instance()->check_user_role(), 'the administrator walks past it');

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertFalse($this->gate()['active'], 'undo opens the site');
    }

    private function check_under_construction_page(): void
    {
        $this->assertTrue(class_exists('UCP'));
        $defaults = \UCP::default_options();
        foreach ([ 'status', 'whitelisted_roles', 'whitelisted_users', 'end_date', 'heading1' ] as $key) {
            $this->assertArrayHasKey($key, $defaults, "the reader relies on {$key}");
        }
        $this->assertFalse($this->gate()['active']);

        $out = $this->toggle('enable-site-gate', [ 'plugin' => 'under-construction-page' ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($this->gate()['active']);

        // The plugin's own decision: a visitor is gated, the administrator who turned it on is not.
        wp_set_current_user(0);
        $this->assertTrue(\UCP::is_construction_mode_enabled(false), 'a visitor gets the page');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->assertFalse(\UCP::is_construction_mode_enabled(false), 'an administrator walks past it');
        $this->assertTrue(\UCP::is_construction_mode_enabled(true));

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertFalse(\UCP::is_construction_mode_enabled(true), 'undo opens the site');
        $this->assertFalse($this->gate()['active']);

        $off = $this->toggle('enable-site-gate', [ 'plugin' => 'under-construction-page' ], true);
        $this->assertTrue($this->gate()['active']);
        $closed = $this->toggle('disable-site-gate', []);
        $this->assertArrayNotHasKey('error', $closed);
        $this->assertFalse(\UCP::is_construction_mode_enabled(true));
        $this->assertArrayNotHasKey('error', $off);
    }

    private function check_maintenance(): void
    {
        $this->assertTrue(class_exists('MTNC'));
        $options = \MTNC::getInstance()->get_options();
        foreach ([ 'status', 'show_logged_in', 'ip_whitelist', 'blockse', 'direct_access_link', 'site_password', 'header_text' ] as $key) {
            $this->assertArrayHasKey($key, $options, "the reader relies on options.{$key}");
        }
        $this->assertFalse($this->gate()['active']);

        $options['status'] = '1';
        $this->assertTrue(\MTNC::getInstance()->update_options('options', $options));

        $gate = $this->gate();
        $this->assertTrue($gate['active']);
        $this->assertSame($options['blockse'] ? 503 : 200, $gate['http_status']);
        $this->assertSame('every logged-in user' === $gate['bypass']['logged_in_users'], '1' === (string) $options['show_logged_in']);
        $this->assertSame('1', (string) \MTNC::getInstance()->get_options()['status'], 'the plugin reads back what the reader saw');

        // The toggle: the nested status the plugin's own loader reads back.
        $options['status']         = '0';
        $options['show_logged_in'] = '1';
        \MTNC::getInstance()->update_options('options', $options);
        $out = $this->toggle('enable-site-gate', [ 'plugin' => 'maintenance' ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $load = new \ReflectionMethod(\MTNC::class, 'load_options');
        $this->assertSame('1', (string) $load->invoke(\MTNC::getInstance())['options']['status'], 'the plugin loads the status the toggle wrote');

        $this->assertArrayNotHasKey('error', $this->toggle('disable-site-gate', [ 'plugin' => 'maintenance' ]));
        $this->assertSame('0', (string) $load->invoke(\MTNC::getInstance())['options']['status']);
    }

    private function check_cmp_coming_soon_maintenance(): void
    {
        $this->assertTrue(class_exists('CMP_Coming_Soon_and_Maintenance'));
        $cmp = \CMP_Coming_Soon_and_Maintenance::instance();
        $this->assertSame('0', $cmp->cmp_active());
        $this->assertFalse($this->gate()['active']);

        update_option('niteoCS_status', '1');
        update_option('niteoCS_activation', '1');
        $this->assertSame('1', $cmp->cmp_active());
        $this->assertSame('1', (string) $cmp->cmp_mode());

        $gate = $this->gate();
        $this->assertTrue($gate['active']);
        $this->assertSame('maintenance', $gate['mode']);
        $this->assertSame(503, $gate['http_status']);
        $this->assertContains('administrator', $gate['bypass']['roles'], 'the plugin lets administrators through');
        $this->assertSame('open', $gate['rest_api'], 'the plugin leaves REST open by default');

        // The toggle: status and mode in the plugin's own options, and the
        // plugin's role filter lets the administrator through.
        update_option('niteoCS_status', '0');
        update_option('niteoCS_activation', '2');
        $out = $this->toggle('enable-site-gate', [ 'plugin' => 'cmp-coming-soon-maintenance', 'mode' => 'maintenance' ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('1', $cmp->cmp_active());
        $this->assertSame('1', (string) $cmp->cmp_mode());
        $this->assertTrue($cmp->cmp_roles_filter(), 'the administrator walks past it');

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('0', $cmp->cmp_active(), 'undo opens the site');
        $this->assertSame('2', (string) $cmp->cmp_mode());
    }

    private function check_coming_soon(): void
    {
        // SeedProd always loads its front door class; its settings save
        // (app/settings.php) is loaded only inside is_admin(), see seedprod_save().
        $this->assertTrue(class_exists('SeedProd_Lite_Render'));
        $this->assertTrue(defined('SEEDPROD_PLUGIN_PATH'));
        $this->assertFalse($this->gate()['active']);

        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Soon', 'post_content_filtered' => wp_json_encode([ 'access_by_role' => [ 'administrator' ] ]) ]);
        update_option('seedprod_coming_soon_page_id', $page);
        update_option('seedprod_settings', wp_json_encode([ 'api_key' => '' ]));

        $out = $this->toggle('enable-site-gate', [ 'plugin' => 'coming-soon', 'mode' => 'coming_soon' ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $gate = $this->gate();
        $this->assertTrue($gate['active']);
        $this->assertSame('coming_soon', $gate['mode']);

        // The plugin's own settings save, fed what a settings screen would send: it
        // keeps the flag we wrote and brings the page to the status that matches.
        $saved = $this->seedprod_save(json_decode((string) get_option('seedprod_settings'), true));
        $this->assertTrue($saved, 'SeedProd accepted its own settings save');
        $stored = json_decode((string) get_option('seedprod_settings'), true);
        $this->assertTrue($stored['enable_coming_soon_mode'], 'the plugin keeps the flag the toggle wrote');
        $this->assertSame('publish', get_post_status($page), 'and publishes the page for the mode that is on');

        // The gate does not wait for that sync: with the page still a draft, the plugin's
        // own front door class hooks the template redirect from the flag alone.
        wp_update_post([ 'ID' => $page, 'post_status' => 'draft' ]);
        remove_all_actions('template_redirect');
        $render = new \SeedProd_Lite_Render();
        $this->assertNotFalse(has_action('template_redirect', [ $render, 'render_comingsoon_page' ]), 'SeedProd gates the site with its page in draft');

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertFalse($this->gate()['active']);
        $this->assertSame('no_change', $this->toggle('disable-site-gate', [ 'plugin' => 'coming-soon' ])['error']['code'] ?? '', 'nothing left to disable after the undo');
    }

    /**
     * Run SeedProd's own settings save the way its screen calls it. The plugin
     * requires app/settings.php only when is_admin() (admin-ajax.php, where its
     * screen posts); a test request is not admin, so load that file the same way.
     */
    private function seedprod_save(array $settings): bool
    {
        if (! function_exists('seedprod_lite_save_settings')) {
            require_once SEEDPROD_PLUGIN_PATH . 'app/settings.php';
        }
        $this->assertTrue(function_exists('seedprod_lite_save_settings'), 'SeedProd still defines its settings save in app/settings.php');
        $_REQUEST['_ajax_nonce'] = wp_create_nonce('seedprod_nonce');
        $_POST['settings']       = wp_slash(wp_json_encode($settings));
        add_filter('wp_doing_ajax', '__return_true');
        $die = static function () {
            return static function (): void {
                throw new \RuntimeException('json_sent');
            };
        };
        add_filter('wp_die_ajax_handler', $die);
        add_filter('wp_die_handler', $die);
        ob_start();
        $sent = false;
        try {
            seedprod_lite_save_settings();
        } catch (\RuntimeException $e) {
            $sent = 'json_sent' === $e->getMessage();
        }
        $body = (string) ob_get_clean();
        remove_filter('wp_doing_ajax', '__return_true');
        remove_filter('wp_die_ajax_handler', $die);
        remove_filter('wp_die_handler', $die);
        unset($_REQUEST['_ajax_nonce'], $_POST['settings']);

        return $sent && false !== strpos($body, 'Settings Updated');
    }
}
