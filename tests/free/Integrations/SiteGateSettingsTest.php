<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Site_Gate_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Maintenance\Site_Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #1099: get-site-gate-settings, set-site-gate-content and
 * set-site-gate-bypass on the theme pair, for the five gate plugins in each
 * one's own stored format (verified against the free plugins from
 * wordpress.org: coming-soon 6.20.13, under-construction-page 5.83,
 * wp-maintenance-mode 2.6.26, maintenance 4.33, cmp-coming-soon-maintenance
 * 4.1.22). Writes are off until the site opts in on the toggles' switch,
 * refuse a change that would gate the acting user out while the gate is on,
 * never return a bypass link or an IP address, and are one snapshot each.
 */
class SiteGateSettingsTest extends \WP_UnitTestCase
{
    private const LINK = 'SENTINELlink42';
    private const IP   = '203.0.113.77';

    private const OPTIONS = [
        'seedprod_settings', 'seedprod_coming_soon_page_id', 'seedprod_maintenance_mode_page_id', 'ucp_options',
        'wpmm_settings', 'wpmm_version', 'wpmm_new_look', 'mtnc-options', 'niteoCS_status', 'niteoCS_activation',
        'niteoCS_roles', 'niteoCS_body_title', 'niteoCS_body', 'niteoCS_logo_id', 'niteoCS_logo_type', 'niteoCS_counter',
        'niteoCS_counter_date', 'niteoCS_bypass', 'niteoCS_bypass_id', 'active_plugins',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        add_filter('pre_http_request', [ $this, 'serve' ], 1, 3);
        update_option('active_plugins', []);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter(Site_Gate_Pack::ENABLE_FILTER, '__return_true');
        $this->fresh_operations();
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [ $this, 'serve' ], 1);
        remove_all_filters(Site_Gate_Pack::ENABLE_FILTER);
        $this->fresh_operations();
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        remove_role('gate_manager');
        parent::tearDown();
    }

    /**
     * The kernel keeps a module's operation definitions for the request (here
     * one PHP process for the whole suite), and whether the writes are
     * opted in is part of them, so a test that changes the switch reads them
     * again.
     */
    private function fresh_operations(): void
    {
        \WPMCP\Plugin::instance()->kernel()->use_registry_for_tests(null);
    }

    public function serve($pre, $args, $url)
    {
        return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => '' ], 'cookies' => [], 'filename' => null ];
    }

    private function activate(string $slug): void
    {
        update_option('active_plugins', array_merge((array) get_option('active_plugins'), [ Site_Gate::PLUGINS[ $slug ] ]));
    }

    private function read(array $args): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => 'get-site-gate-settings', 'args' => $args ]);
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Theme_Integration())->handle_write($call);
    }

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    private function no_snapshot(): void
    {
        $this->assertSame([], Snapshot_Store::list_by_session('default'), 'a refusal takes no undo point');
    }

    private function undo(array $out): void
    {
        $this->assertNotEmpty($out['operation_id'] ?? null, (string) wp_json_encode($out));
        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
    }

    /** An acting user who manages options but is not a super admin (no delete_users). */
    private function act_as_manager(): void
    {
        add_role('gate_manager', 'Gate manager', [ 'read' => true, 'manage_options' => true, 'edit_theme_options' => true, 'edit_posts' => true ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'gate_manager' ]));
    }

    private function image(): int
    {
        return self::factory()->attachment->create_object([ 'file' => 'logo.png', 'post_mime_type' => 'image/png', 'post_type' => 'attachment' ]);
    }

    /** The Maintenance plugin keeps its designer themes as stdClass trees (json_decode without assoc). */
    private function maintenance_options(array $access = []): array
    {
        $theme = json_decode((string) wp_json_encode([
            'modules_order' => [ 'logo-1', 'header-1', 'content-1' ],
            'modules'       => [
                'logo-1'    => [ 'type' => 'logo', 'groups' => [ 'logo' => [ 'fields' => [ 'logo' => [ 'value' => 'https://example.org/old.png' ], 'width' => [ 'value' => '' ] ] ] ] ],
                'header-1'  => [ 'type' => 'header', 'groups' => [ 'header' => [ 'fields' => [ 'text' => [ 'value' => 'Old heading' ], 'font' => [ 'value' => 'Open Sans' ] ] ] ] ],
                'content-1' => [ 'type' => 'content', 'groups' => [ 'col1' => [ 'fields' => [ 'text' => [ 'value' => 'Old message' ] ] ] ] ],
            ],
            'custom_css'    => '',
        ]));

        return [
            'options' => $access + [
                'status'             => '0',
                'show_logged_in'     => '1',
                'ip_whitelist'       => '',
                'direct_access_link' => '',
                'site_password'      => 'SENTINEL-PW',
                'theme_global'       => 'abc',
                'themes'             => [ 'abc' => $theme ],
            ],
            'meta'    => [ 'first_version' => '4.33' ],
        ];
    }

    // ---------------------------------------------------------------
    // Catalog and opt-in
    // ---------------------------------------------------------------

    public function test_the_ops_are_on_the_theme_pair_and_writes_are_off_until_opt_in(): void
    {
        remove_all_filters(Site_Gate_Pack::ENABLE_FILTER);
        $this->fresh_operations();
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');

        $this->assertSame('read', $ops['get-site-gate-settings']['mode']);
        $this->assertSame('write', $ops['set-site-gate-content']['mode']);
        $this->assertSame('destructive', $ops['set-site-gate-bypass']['mode']);
        $this->assertTrue($ops['get-site-gate-settings']['enabled']);
        $this->assertFalse($ops['set-site-gate-content']['enabled']);
        $this->assertFalse($ops['set-site-gate-bypass']['enabled']);

        $this->activate('under-construction-page');
        $out = $this->write('set-site-gate-content', [ 'plugin' => 'under-construction-page', 'headline' => 'Soon' ]);
        $this->assertSame('opt_in_required', $this->code($out));
        $this->assertStringContainsString(Site_Gate_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertFalse(get_option('ucp_options'));
        $this->no_snapshot();
    }

    public function test_a_call_with_nothing_to_change_and_an_inactive_plugin_are_refused(): void
    {
        $this->assertSame('site_gate_plugin_inactive', $this->code($this->write('set-site-gate-content', [ 'plugin' => 'maintenance', 'headline' => 'x' ])));

        $this->activate('under-construction-page');
        $this->assertSame('nothing_to_change', $this->code($this->write('set-site-gate-content', [ 'plugin' => 'auto' ])));
        $this->assertSame('nothing_to_change', $this->code($this->write('set-site-gate-bypass', [], true)));

        update_option('ucp_options', [ 'heading1' => 'Same' ]);
        $this->assertSame('no_change', $this->code($this->write('set-site-gate-content', [ 'headline' => 'Same' ])));
        $this->no_snapshot();
    }

    // ---------------------------------------------------------------
    // Under Construction
    // ---------------------------------------------------------------

    public function test_under_construction_content_writes_its_heading_and_text_keeps_the_rest_and_undoes(): void
    {
        $this->activate('under-construction-page');
        $before = [ 'status' => '1', 'heading1' => 'Old', 'content' => 'Old text', 'license_key' => 'SENTINEL-KEY', 'whitelisted_roles' => [ 'administrator' ] ];
        update_option('ucp_options', $before);

        $out = $this->write('set-site-gate-content', [ 'headline' => 'Back <b>soon</b>', 'message' => '<p>Working <script>x</script></p>' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $stored = get_option('ucp_options');
        $this->assertSame('Back <b>soon</b>', $stored['heading1'], 'the plugin allows post HTML in its heading');
        $this->assertSame('<p>Working x</p>', $stored['content']);
        $this->assertSame('SENTINEL-KEY', $stored['license_key']);
        $this->assertSame('Back <b>soon</b>', $out['result']['content']['headline']);
        $this->assertStringNotContainsString('SENTINEL', (string) wp_json_encode($out));

        $this->undo($out);
        $this->assertSame($before, get_option('ucp_options'));
    }

    public function test_under_construction_refuses_a_logo_and_a_countdown_it_does_not_have(): void
    {
        $this->activate('under-construction-page');
        $out = $this->write('set-site-gate-content', [ 'logo' => $this->image() ]);

        $this->assertSame('field_unsupported', $this->code($out));
        $this->assertSame([ 'headline', 'message' ], $out['error']['data']['supported']);
        $this->assertSame('field_unsupported', $this->code($this->write('set-site-gate-content', [ 'countdown' => '2030-01-01T00:00:00Z' ])));
        $this->assertSame('field_unsupported', $this->code($this->write('set-site-gate-bypass', [ 'ip_allowlist' => [ self::IP ] ], true)));
        $this->no_snapshot();
    }

    public function test_under_construction_bypass_refuses_a_lockout_while_on_and_writes_while_off(): void
    {
        $this->activate('under-construction-page');
        $before = [ 'status' => '1', 'whitelisted_roles' => [ 'administrator' ], 'whitelisted_users' => [] ];
        update_option('ucp_options', $before);

        $out = $this->write('set-site-gate-bypass', [ 'roles' => [ 'editor' ] ], true);
        $this->assertSame('admin_not_bypassed', $this->code($out));
        $this->assertSame($before, get_option('ucp_options'));
        $this->no_snapshot();

        $me  = get_current_user_id();
        $out = $this->write('set-site-gate-bypass', [ 'roles' => [ 'editor' ], 'users' => [ $me ] ], true);
        $this->assertArrayNotHasKey('error', $out, 'a listed user still walks past it');
        $this->assertSame([ 'editor' ], get_option('ucp_options')['whitelisted_roles']);
        $this->assertSame([ (string) $me ], get_option('ucp_options')['whitelisted_users']);
        $this->assertSame(1, $out['result']['bypass']['user_count']);
        $this->undo($out);
        $this->assertSame($before, get_option('ucp_options'));

        update_option('ucp_options', [ 'status' => '0' ] + $before);
        $this->assertArrayNotHasKey('error', $this->write('set-site-gate-bypass', [ 'roles' => [ 'editor' ] ], true), 'off, nobody is gated out');
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $this->activate('under-construction-page');
        $out = $this->write('set-site-gate-bypass', [ 'roles' => [ 'administrator', 'no_such_role' ] ], true);

        $this->assertSame('unknown_role', $this->code($out));
        $this->assertSame([ 'no_such_role' ], $out['error']['data']['unknown']);
    }

    // ---------------------------------------------------------------
    // WP Maintenance Mode (LightStart)
    // ---------------------------------------------------------------

    private function wpmm(array $general = [], array $design = [], array $modules = []): array
    {
        $settings = [
            'general' => $general + [ 'status' => 0, 'frontend_role' => [], 'backend_role' => [], 'exclude' => [ 'feed', 'wp-login', '198.51.100.9 # office' ], 'bypass_bots' => 0 ],
            'design'  => $design + [ 'title' => 'Maintenance mode', 'heading' => 'Maintenance mode', 'text' => '<p>Sorry</p>' ],
            'modules' => $modules + [ 'countdown_status' => 0, 'countdown_start' => '2026-01-01 00:00:00', 'countdown_details' => [ 'days' => 0, 'hours' => 1, 'minutes' => 0 ] ],
        ];
        update_option('wpmm_settings', $settings);
        return $settings;
    }

    public function test_wp_maintenance_mode_content_writes_its_classic_design_and_countdown_and_undoes(): void
    {
        $this->activate('wp-maintenance-mode');
        $before = $this->wpmm();
        $end    = time() + 2 * DAY_IN_SECONDS + 3 * HOUR_IN_SECONDS + 600;

        $out = $this->write('set-site-gate-content', [ 'headline' => 'Upgrading <b>now</b>', 'message' => '<p>Back at noon</p>', 'countdown' => gmdate('Y-m-d\TH:i:s\Z', $end) ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $stored = get_option('wpmm_settings');
        $this->assertSame('Upgrading now', $stored['design']['heading'], 'the plugin saves its heading as plain text');
        $this->assertSame('<p>Back at noon</p>', $stored['design']['text']);
        $this->assertSame(1, $stored['modules']['countdown_status'], 'the plugin compares the countdown status with === 1');
        $this->assertSame('2', (string) $stored['modules']['countdown_details']['days']);
        $this->assertSame('3', (string) $stored['modules']['countdown_details']['hours']);
        $this->assertEqualsWithDelta($end, strtotime($stored['modules']['countdown_start'] . ' +' . ($stored['modules']['countdown_details']['days'] * DAY_IN_SECONDS + $stored['modules']['countdown_details']['hours'] * HOUR_IN_SECONDS + $stored['modules']['countdown_details']['minutes'] * MINUTE_IN_SECONDS) . ' seconds'), 90);
        $this->assertSame($before['general'], $stored['general']);

        $this->undo($out);
        $this->assertSame($before, get_option('wpmm_settings'));

        $this->assertSame('no_change', $this->code($this->write('set-site-gate-content', [ 'countdown' => null ])), 'the countdown is already off');
        $this->write('set-site-gate-content', [ 'countdown' => gmdate('c', $end) ]);
        $off = $this->write('set-site-gate-content', [ 'countdown' => null ]);
        $this->assertArrayNotHasKey('error', $off, (string) wp_json_encode($off));
        $this->assertSame(0, get_option('wpmm_settings')['modules']['countdown_status']);
    }

    public function test_wp_maintenance_mode_with_its_block_page_refuses_content_naming_the_page(): void
    {
        $this->activate('wp-maintenance-mode');
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $this->wpmm([], [ 'page_id' => $page ]);
        update_option('wpmm_new_look', '1');

        $out = $this->write('set-site-gate-content', [ 'headline' => 'x' ]);

        $this->assertSame('wpmm_page_content', $this->code($out));
        $this->assertSame($page, $out['error']['data']['page_id']);
        $this->assertSame($page, $this->read([])['result']['content']['page_id']);
        $this->no_snapshot();
    }

    public function test_wp_maintenance_mode_bypass_replaces_only_the_ip_entries_masks_them_and_refuses_a_lockout(): void
    {
        $this->activate('wp-maintenance-mode');
        $before = $this->wpmm([ 'status' => 1, 'frontend_role' => [ 'gate_manager' ], 'backend_role' => [ 'gate_manager' ] ]);
        $this->act_as_manager();

        $read = $this->read([])['result'];
        $this->assertSame(1, $read['bypass']['ip_count']);
        $this->assertSame(2, $read['bypass']['excluded_path_count']);
        $this->assertStringNotContainsString('198.51.100.9', (string) wp_json_encode($read), 'IP addresses are never read out');

        $out = $this->write('set-site-gate-bypass', [ 'ip_allowlist' => [ self::IP, '2001:db8::1' ] ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ 'feed', 'wp-login', self::IP, '2001:db8::1' ], get_option('wpmm_settings')['general']['exclude']);
        $this->assertSame(2, $out['result']['bypass']['ip_count']);
        $this->assertStringNotContainsString(self::IP, (string) wp_json_encode($out), 'nor echoed by a write');
        $this->undo($out);
        $this->assertSame($before, get_option('wpmm_settings'));

        $out = $this->write('set-site-gate-bypass', [ 'backend_roles' => [ 'editor' ] ], true);
        $this->assertSame('admin_not_bypassed', $this->code($out), 'the manager would lose wp-admin while it is on');
        $this->assertSame($before, get_option('wpmm_settings'));

        $this->assertSame('invalid_ip', $this->code($this->write('set-site-gate-bypass', [ 'ip_allowlist' => [ 'not-an-ip' ] ], true)));
    }

    // ---------------------------------------------------------------
    // Maintenance
    // ---------------------------------------------------------------

    public function test_maintenance_content_edits_its_designer_modules_and_undo_puts_the_objects_back(): void
    {
        $this->activate('maintenance');
        $before = $this->maintenance_options();
        update_option('mtnc-options', $before);
        $logo = $this->image();

        $read = $this->read([ 'plugin' => 'maintenance' ])['result'];
        $this->assertSame('Old heading', $read['content']['headline']);
        $this->assertSame('Old message', $read['content']['message']);

        $out = $this->write('set-site-gate-content', [ 'headline' => 'New heading', 'message' => '<p>New message</p>', 'logo' => $logo ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $theme = get_option('mtnc-options')['options']['themes']['abc'];
        $this->assertIsObject($theme, 'the plugin reads its theme with -> so it stays an object');
        $this->assertSame('New heading', $theme->modules->{'header-1'}->groups->header->fields->text->value);
        $this->assertSame('<p>New message</p>', $theme->modules->{'content-1'}->groups->col1->fields->text->value);
        $this->assertSame(wp_get_attachment_url($logo), $theme->modules->{'logo-1'}->groups->logo->fields->logo->value);
        $this->assertSame('Open Sans', $theme->modules->{'header-1'}->groups->header->fields->font->value);
        $this->assertStringNotContainsString('SENTINEL', (string) wp_json_encode($out));

        $this->undo($out);
        $after = get_option('mtnc-options');
        $this->assertIsObject($after['options']['themes']['abc'], 'undo restores the theme as an object, not an array');
        $this->assertEquals($before, $after);
    }

    public function test_maintenance_refuses_a_module_its_theme_does_not_have_and_a_countdown(): void
    {
        $this->activate('maintenance');
        $options = $this->maintenance_options();
        unset($options['options']['themes']['abc']->modules->{'logo-1'});
        update_option('mtnc-options', $options);

        $out = $this->write('set-site-gate-content', [ 'logo' => $this->image() ]);
        $this->assertSame('content_field_missing', $this->code($out));
        $this->assertStringContainsString('Maintenance', $out['error']['message']);
        $this->assertSame('field_unsupported', $this->code($this->write('set-site-gate-content', [ 'countdown' => '2030-01-01T00:00:00Z' ])));
        $this->no_snapshot();
    }

    public function test_maintenance_bypass_writes_ips_and_link_never_returns_them_and_refuses_a_lockout(): void
    {
        $this->activate('maintenance');
        $before = $this->maintenance_options([ 'status' => '1' ]);
        update_option('mtnc-options', $before);

        $ask = $this->write('set-site-gate-bypass', [ 'ip_allowlist' => [ self::IP ], 'bypass_link' => self::LINK ]);
        $this->assertSame('confirmation_required', $this->code($ask));
        $this->assertStringNotContainsString(self::LINK, (string) wp_json_encode($ask), 'the preview never shows the link');
        $this->assertStringNotContainsString(self::IP, (string) wp_json_encode($ask));

        $out = $this->write('set-site-gate-bypass', [ 'ip_allowlist' => [ self::IP ], 'bypass_link' => self::LINK ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $stored = get_option('mtnc-options')['options'];
        $this->assertSame(self::IP, $stored['ip_whitelist']);
        $this->assertSame(self::LINK, $stored['direct_access_link']);
        $this->assertIsObject($stored['themes']['abc']);
        $this->assertTrue($out['result']['bypass']['bypass_link_set']);
        $this->assertSame(1, $out['result']['bypass']['ip_count']);
        $json = (string) wp_json_encode($out) . wp_json_encode($this->read([]));
        foreach ([ self::LINK, self::IP, 'SENTINEL-PW' ] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->undo($out);
        $this->assertEquals($before, get_option('mtnc-options'));

        $out = $this->write('set-site-gate-bypass', [ 'logged_in_bypass' => false ], true);
        $this->assertSame('admin_not_bypassed', $this->code($out));
        $this->assertSame('invalid_args', $this->code($this->write('set-site-gate-bypass', [ 'bypass_link' => 'short' ], true)));
    }

    public function test_undoing_a_maintenance_toggle_keeps_its_designer_themes_objects(): void
    {
        $this->activate('maintenance');
        update_option('mtnc-options', $this->maintenance_options());

        $out = $this->write('enable-site-gate', [ 'plugin' => 'maintenance' ], true);
        $this->undo($out);

        $this->assertIsObject(get_option('mtnc-options')['options']['themes']['abc']);
        $this->assertEquals($this->maintenance_options(), get_option('mtnc-options'));
    }

    // ---------------------------------------------------------------
    // CMP
    // ---------------------------------------------------------------

    public function test_cmp_content_writes_its_options_and_undo_restores_exactly_those(): void
    {
        $this->activate('cmp-coming-soon-maintenance');
        update_option('niteoCS_body_title', 'Old title');
        update_option('niteoCS_logo_type', 'text');
        $logo = $this->image();
        $end  = time() + DAY_IN_SECONDS;

        $out = $this->write('set-site-gate-content', [ 'headline' => 'Launching', 'message' => '<p>Soon</p>', 'logo' => $logo, 'countdown' => gmdate('c', $end) ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('Launching', get_option('niteoCS_body_title'));
        $this->assertSame('<p>Soon</p>', get_option('niteoCS_body'));
        $this->assertSame((string) $logo, get_option('niteoCS_logo_id'));
        $this->assertSame('graphic', get_option('niteoCS_logo_type'));
        $this->assertSame('1', get_option('niteoCS_counter'));
        $this->assertSame((string) $end, get_option('niteoCS_counter_date'));
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $end), $out['result']['content']['countdown']);

        $this->undo($out);
        $this->assertSame('Old title', get_option('niteoCS_body_title'));
        $this->assertSame('text', get_option('niteoCS_logo_type'));
        foreach ([ 'niteoCS_body', 'niteoCS_logo_id', 'niteoCS_counter', 'niteoCS_counter_date' ] as $created) {
            $this->assertFalse(get_option($created), "{$created} did not exist before, so undo removes it");
        }

        $this->assertSame('countdown_in_past', $this->code($this->write('set-site-gate-content', [ 'countdown' => gmdate('c', time() - 60) ])));
        $this->assertSame('invalid_logo', $this->code($this->write('set-site-gate-content', [ 'logo' => 999999 ])));
    }

    public function test_cmp_bypass_link_turns_on_is_masked_and_roles_cannot_lock_out_while_on(): void
    {
        $this->activate('cmp-coming-soon-maintenance');
        update_option('niteoCS_status', '1');
        update_option('niteoCS_roles', wp_json_encode([ 'gate_manager' ]));
        $this->act_as_manager();

        $out = $this->write('set-site-gate-bypass', [ 'bypass_link' => self::LINK ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame(self::LINK, get_option('niteoCS_bypass_id'));
        $this->assertSame('1', get_option('niteoCS_bypass'));
        $this->assertTrue($out['result']['bypass']['bypass_link_set']);
        $this->assertStringNotContainsString(self::LINK, (string) wp_json_encode($out) . wp_json_encode($this->read([])));
        $this->undo($out);
        $this->assertFalse(get_option('niteoCS_bypass_id'));
        $this->assertFalse(get_option('niteoCS_bypass'));

        $this->assertSame('admin_not_bypassed', $this->code($this->write('set-site-gate-bypass', [ 'roles' => [ 'editor' ] ], true)));
        $this->assertSame(wp_json_encode([ 'gate_manager' ]), get_option('niteoCS_roles'));

        $this->assertArrayNotHasKey('error', $this->write('set-site-gate-bypass', [ 'roles' => [ 'editor', 'gate_manager' ] ], true));
        $this->assertSame([ 'editor', 'gate_manager' ], json_decode(get_option('niteoCS_roles'), true));

        update_option('niteoCS_bypass', '1');
        update_option('niteoCS_bypass_id', self::LINK);
        $this->assertArrayNotHasKey('error', $this->write('set-site-gate-bypass', [ 'bypass_link' => '' ], true));
        $this->assertSame('0', get_option('niteoCS_bypass'), 'an empty link turns it off');
    }

    // ---------------------------------------------------------------
    // SeedProd
    // ---------------------------------------------------------------

    private function seedprod_page(array $json): int
    {
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Soon', 'post_content' => '<div>Built HTML</div>', 'post_content_filtered' => wp_json_encode($json, JSON_UNESCAPED_SLASHES) ]);
        update_option('seedprod_coming_soon_page_id', $page);
        return $page;
    }

    public function test_seedprod_content_is_refused_naming_its_builder(): void
    {
        $this->activate('coming-soon');
        $page = $this->seedprod_page([ 'access_by_role' => [], 'document' => [ 'sections' => [] ] ]);

        $out = $this->write('set-site-gate-content', [ 'headline' => 'x' ]);

        $this->assertSame('seedprod_builder_content', $this->code($out));
        $this->assertStringContainsString('seedprod_lite_builder', (string) $out['error']['data']['editor_url']);
        $this->assertSame($page, $out['error']['data']['page_id']);
        $this->no_snapshot();
    }

    public function test_seedprod_bypass_roles_edit_only_its_page_json_mask_the_phrase_and_undo_the_post(): void
    {
        $this->activate('coming-soon');
        update_option('seedprod_settings', wp_json_encode([ 'enable_coming_soon_mode' => true, 'enable_maintenance_mode' => false ]));
        $page   = $this->seedprod_page([ 'access_by_role' => [ 'administrator' ], 'bypass_phrase' => self::LINK, 'document' => [ 'sections' => [ [ 'id' => 'a1', 'settings' => new \stdClass() ] ] ], 'redirect_url' => 'https://example.org/x' ]);
        $before = get_post($page)->post_content_filtered;

        $read = $this->read([])['result'];
        $this->assertTrue($read['bypass']['bypass_link_set']);
        $this->assertStringNotContainsString(self::LINK, (string) wp_json_encode($read));

        $this->assertSame('admin_not_bypassed', $this->code($this->write('set-site-gate-bypass', [ 'roles' => [ 'editor' ] ], true)));
        $this->no_snapshot();
        $this->assertSame('field_unsupported', $this->code($this->write('set-site-gate-bypass', [ 'bypass_link' => self::LINK . 'x' ], true)));

        $out = $this->write('set-site-gate-bypass', [ 'roles' => [ 'editor', 'anyoneloggedin' ] ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $json = json_decode(get_post($page)->post_content_filtered);
        $this->assertSame([ 'editor', 'anyoneloggedin' ], $json->access_by_role);
        $this->assertSame(self::LINK, $json->bypass_phrase);
        $this->assertIsObject($json->document->sections[0]->settings, 'an empty object in the builder JSON stays an object');
        $this->assertSame('https://example.org/x', $json->redirect_url);
        $this->assertSame('<div>Built HTML</div>', get_post($page)->post_content);
        $this->assertStringNotContainsString(self::LINK, (string) wp_json_encode($out));

        $this->undo($out);
        clean_post_cache($page);
        $this->assertSame($before, get_post($page)->post_content_filtered);
    }
}
