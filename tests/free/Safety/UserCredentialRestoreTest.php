<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Admin\Opt_In_Settings_Page;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Governance\Opt_In_Settings;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\Users\Update_User;

/**
 * Restoring a user snapshot leaves the user's login sessions
 * (session_tokens) and application passwords (_application_passwords) as
 * they are now, so a session or an application password revoked after the
 * snapshot stays revoked, and the result names the keys it left. An
 * administrator can switch this on with the wpmcp_enable_user_credential_restore
 * opt-in: manage_options on a single site, and on a network only a network
 * administrator, network-wide.
 */
final class UserCredentialRestoreTest extends \WP_UnitTestCase
{
    private const FILTER = 'wpmcp_enable_user_credential_restore';

    private const SESSIONS = [ 'a1b2c3' => [ 'expiration' => 4102444800, 'login' => 1700000000 ] ];

    private const APP_PASSWORDS = [ [ 'uuid' => 'c0ffee00-0000-4000-8000-000000000001', 'name' => 'agent', 'password' => '$P$hash', 'created' => 1700000000, 'last_used' => null, 'last_ip' => null ] ];

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Opt_In_Settings::register();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        delete_option(Opt_In_Settings::OPTION);
        delete_site_option(Opt_In_Settings::NETWORK_OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        if (is_multisite()) {
            grant_super_admin($this->admin);
        }
        wp_set_current_user($this->admin);
    }

    protected function tearDown(): void
    {
        delete_option(Opt_In_Settings::OPTION);
        delete_site_option(Opt_In_Settings::NETWORK_OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** A subscriber holding one login session and one application password. */
    private function user_with_credentials(): int
    {
        $user = self::factory()->user->create([ 'role' => 'subscriber', 'user_email' => 'old@example.test' ]);
        update_user_meta($user, 'session_tokens', self::SESSIONS);
        update_user_meta($user, '_application_passwords', self::APP_PASSWORDS);
        return $user;
    }

    /** The administrator's update-user of $user's email; returns its operation id. */
    private function change_email(int $user, string $session = 'default'): string
    {
        wp_set_current_user($this->admin);
        $out = (new Update_User())->handle([ 'id' => $user, 'email' => 'new@example.test', 'session_id' => $session ]);
        return (string) $out['operation_id'];
    }

    /** Ends every session and revokes every application password, as wp-admin's "Log out everywhere" and "Revoke all" do. */
    private function revoke(int $user): void
    {
        delete_user_meta($user, 'session_tokens');
        delete_user_meta($user, '_application_passwords');
    }

    private function email(int $user): string
    {
        clean_user_cache($user);
        return (string) get_userdata($user)->user_email;
    }

    private function switch_on(): void
    {
        $this->assertTrue(Opt_In_Settings::set(self::FILTER, true, is_multisite() ? 'network' : 'site'));
    }

    private function post(bool $enabled): array
    {
        return [
            'wpmcp_opt_in_action' => 'toggle',
            '_wpnonce'            => wp_create_nonce(Opt_In_Settings_Page::NONCE_ACTION),
            'filter'              => self::FILTER,
            'enabled'             => $enabled ? '1' : '0',
        ];
    }

    // The restore.

    public function test_by_default_a_user_restore_leaves_revoked_sessions_and_application_passwords_revoked(): void
    {
        $user         = $this->user_with_credentials();
        $operation_id = $this->change_email($user);
        $this->revoke($user);

        $out = (new Rollback_Operation())->handle([ 'operation_id' => $operation_id ]);

        $this->assertTrue($out['restored'], (string) wp_json_encode($out));
        $this->assertSame('old@example.test', $this->email($user));
        $this->assertSame('', get_user_meta($user, 'session_tokens', true));
        $this->assertSame('', get_user_meta($user, '_application_passwords', true));
        $note = implode(' ', $out['warnings']);
        $this->assertStringContainsString('session_tokens', $note);
        $this->assertStringContainsString('_application_passwords', $note);
    }

    public function test_by_default_sessions_and_application_passwords_made_since_the_snapshot_are_kept(): void
    {
        $user = self::factory()->user->create([ 'role' => 'subscriber', 'user_email' => 'old@example.test' ]);
        $operation_id = $this->change_email($user);
        update_user_meta($user, 'session_tokens', self::SESSIONS);
        update_user_meta($user, '_application_passwords', self::APP_PASSWORDS);

        $this->assertTrue((new Rollback_Operation())->handle([ 'operation_id' => $operation_id ])['restored']);

        $this->assertSame('old@example.test', $this->email($user));
        $this->assertSame(self::SESSIONS, get_user_meta($user, 'session_tokens', true));
        $this->assertSame(self::APP_PASSWORDS, get_user_meta($user, '_application_passwords', true));
    }

    public function test_a_snapshot_taken_before_the_rule_still_restores_without_them(): void
    {
        $user = $this->user_with_credentials();
        // An undo point as earlier builds recorded it: every usermeta key.
        $operation_id = wp_generate_uuid4();
        Snapshot_Store::save($operation_id, 'default', [
            'object_type' => 'user',
            'object_id'   => $user,
            'data'        => [
                'fields' => [ 'user_email' => 'old@example.test' ],
                'meta'   => get_user_meta($user),
            ],
        ], 'update-user', '');
        wp_update_user([ 'ID' => $user, 'user_email' => 'new@example.test' ]);
        $this->revoke($user);

        $this->assertTrue((new Rollback_Operation())->handle([ 'operation_id' => $operation_id ])['restored']);

        $this->assertSame('old@example.test', $this->email($user));
        $this->assertSame('', get_user_meta($user, 'session_tokens', true));
        $this->assertSame('', get_user_meta($user, '_application_passwords', true));
    }

    public function test_a_session_rollback_leaves_them_too(): void
    {
        $user = $this->user_with_credentials();
        $this->change_email($user, 'profile-run');
        $this->revoke($user);

        $out = (new Rollback_Session())->handle([ 'session_id' => 'profile-run' ]);

        $this->assertSame(1, $out['restored_count']);
        $this->assertSame('old@example.test', $this->email($user));
        $this->assertSame('', get_user_meta($user, 'session_tokens', true));
        $this->assertSame('', get_user_meta($user, '_application_passwords', true));
        $this->assertStringContainsString('_application_passwords', implode(' ', $out['warnings']));
    }

    public function test_with_the_setting_on_the_restore_brings_them_back(): void
    {
        $user         = $this->user_with_credentials();
        $operation_id = $this->change_email($user);
        $this->revoke($user);
        $this->switch_on();

        $out = (new Rollback_Operation())->handle([ 'operation_id' => $operation_id ]);

        $this->assertTrue($out['restored']);
        $this->assertSame('old@example.test', $this->email($user));
        $this->assertSame(self::SESSIONS, get_user_meta($user, 'session_tokens', true));
        $this->assertSame(self::APP_PASSWORDS, get_user_meta($user, '_application_passwords', true));
        $this->assertStringNotContainsString('_application_passwords', implode(' ', $out['warnings']));
    }

    // The setting.

    public function test_the_setting_is_off_by_default_and_says_plainly_what_it_restores(): void
    {
        $this->assertFalse(Opt_In_Settings::is_on(self::FILTER));
        $this->assertFalse((bool) apply_filters(self::FILTER, false));
        $label = Opt_In_Settings::labels()[ self::FILTER ] ?? '';
        $this->assertStringContainsString('login sessions', $label);
        $this->assertStringContainsString('application passwords', $label);
    }

    public function test_a_non_administrator_cannot_change_the_setting(): void
    {
        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        wp_set_current_user($editor);

        $this->assertArrayHasKey('error', (new Opt_In_Settings_Page())->handle_request($this->post(true)));
        if (is_multisite()) {
            $this->assertArrayHasKey('error', (new Opt_In_Settings_Page())->handle_request($this->post(true), 'network'));
        }

        $this->assertFalse(Opt_In_Settings::is_on(self::FILTER));
        $this->assertSame([], Governance_Audit_Log::list());
    }

    public function test_on_a_single_site_an_administrator_switches_it_on_from_the_screen(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('Single site only.');
        }

        $result = (new Opt_In_Settings_Page())->handle_request($this->post(true));

        $this->assertSame('toggle', $result['action'] ?? null, (string) ($result['error'] ?? ''));
        $this->assertTrue(Opt_In_Settings::is_on(self::FILTER));
        $this->assertSame('opt-in:' . self::FILTER, Governance_Audit_Log::list()[0]['ability']);
    }

    public function test_on_a_network_only_a_network_administrator_switches_it_on_network_wide(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only.');
        }
        $page       = new Opt_In_Settings_Page();
        $site_admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($site_admin);
        $this->assertTrue(current_user_can('manage_options'));

        // A site admin can neither switch it on for their site nor for the network.
        $this->assertArrayHasKey('error', $page->handle_request($this->post(true)));
        $this->assertArrayHasKey('error', $page->handle_request($this->post(true), 'network'));
        // Nor does a per-site value count on a network, however it was stored.
        update_option(Opt_In_Settings::OPTION, [ self::FILTER => 1 ]);
        $this->assertFalse(Opt_In_Settings::set(self::FILTER, true, 'site'));
        $this->assertFalse(Opt_In_Settings::is_on(self::FILTER));
        $this->assertFalse((bool) apply_filters(self::FILTER, false));
        delete_option(Opt_In_Settings::OPTION);

        // The network administrator, on the network screen.
        wp_set_current_user($this->admin);
        $this->assertArrayHasKey('error', $page->handle_request($this->post(true)), 'not from a site screen');
        $result = $page->handle_request($this->post(true), 'network');
        $this->assertSame('toggle', $result['action'] ?? null, (string) ($result['error'] ?? ''));
        $this->assertTrue(Opt_In_Settings::is_on(self::FILTER));

        // A test plugin still hooks the deprecated wpmu_new_blog.
        $this->setExpectedDeprecated('wpmu_new_blog');
        $blog = self::factory()->blog->create();
        switch_to_blog($blog);
        $this->assertTrue((bool) apply_filters(self::FILTER, false), 'network-wide reaches every site');
        restore_current_blog();
    }
}
