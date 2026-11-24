<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Admin\History_Page;
use WPMCP\Safety\Post_Write_Refused;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\Users\Update_User;

/**
 * Who may undo what: undo checks the current user's capabilities and
 * operation ownership. Every undo is authorized
 * against the CURRENT user when it runs:
 *
 *  - Ownership: below manage_options a caller undoes only the operations
 *    they made. On a network, an operation a super admin made (or one made
 *    with no user) is undone by someone else only by a super admin.
 *  - Capability: undoing takes what making the change takes, per object:
 *    edit_post on a post, edit_user on a user plus promote_user when the
 *    undo would change their role, update_plugins for a plugin package, and
 *    manage_options for any type that names nothing (fail closed).
 *
 * rollback-operation refuses with a WP_Error (status 403, code
 * rollback_not_owner or rollback_capability_required), the refusal envelope
 * on the wire. rollback-session is all or nothing: one operation the caller
 * may not undo refuses the whole call (Post_Write_Refused carrying that
 * WP_Error) and nothing is restored, the catch-all 'default' session
 * included.
 */
final class RollbackAuthorizationTest extends \WP_UnitTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($this->admin);
        }
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** A snapshotted title change of $post_id by $user_id; returns its operation id. */
    private function edit_post(int $user_id, int $post_id, string $title, string $session = 'default'): string
    {
        wp_set_current_user($user_id);
        $operation_id = wp_generate_uuid4();
        Safe_Mutation::run(
            ['object_type' => 'post', 'object_id' => $post_id, 'session_id' => $session, 'tool_name' => 'update-post', 'args' => [], 'operation_id' => $operation_id],
            static function () use ($post_id, $title): bool {
                wp_update_post(['ID' => $post_id, 'post_title' => $title]);
                return true;
            }
        );

        return $operation_id;
    }

    /** A snapshotted email change of $target by $user_id; returns its operation id. */
    private function edit_user(int $user_id, int $target, string $email, string $session = 'default'): string
    {
        wp_set_current_user($user_id);
        $operation_id = wp_generate_uuid4();
        Safe_Mutation::run(
            ['object_type' => 'user', 'object_id' => $target, 'session_id' => $session, 'tool_name' => 'update-user', 'args' => [], 'operation_id' => $operation_id],
            static function () use ($target, $email): bool {
                wp_update_user(['ID' => $target, 'user_email' => $email]);
                return true;
            }
        );

        return $operation_id;
    }

    /** An administrator's update-user of an Editor, through the tool; returns the editor's id and the operation id. */
    private function admin_edits_a_user(): array
    {
        $target = self::factory()->user->create(['role' => 'editor', 'user_email' => 'old@example.test']);
        wp_set_current_user($this->admin);
        $out = (new Update_User())->handle(['id' => $target, 'email' => 'new@example.test']);

        return [$target, (string) $out['operation_id']];
    }

    /** A raw undo point of $type recorded as made by $user_id. */
    private function save_row(int $user_id, string $type, array $data, string $session = 'default', int $object_id = 0): string
    {
        wp_set_current_user($user_id);
        $operation_id = wp_generate_uuid4();
        Snapshot_Store::save($operation_id, $session, ['object_type' => $type, 'object_id' => $object_id, 'data' => $data], 'test-tool', '');

        return $operation_id;
    }

    private function assert_refused(mixed $out, string $code): \WP_Error
    {
        $this->assertWPError($out);
        $this->assertSame($code, $out->get_error_code(), $out->get_error_message());
        $this->assertSame(403, $out->get_error_data()['status'] ?? null);

        return $out;
    }

    /** rollback-session's refusal: the WP_Error a Post_Write_Refused carries. */
    private function session_refusal(string $session_id, string $code): \WP_Error
    {
        try {
            (new Rollback_Session())->handle(['session_id' => $session_id]);
        } catch (Post_Write_Refused $e) {
            return $this->assert_refused($e->error(), $code);
        }
        $this->fail(sprintf('rollback-session "%s" was not refused.', $session_id));
    }

    private function user_email(int $user_id): string
    {
        clean_user_cache($user_id);
        return (string) get_userdata($user_id)->user_email;
    }

    private function user_roles(int $user_id): array
    {
        clean_user_cache($user_id);
        return array_values(get_userdata($user_id)->roles);
    }

    private function undone(string $operation_id): bool
    {
        return null !== Snapshot_Store::rolled_back_at((array) Snapshot_Store::get_by_operation($operation_id));
    }

    // A lower role and an administrator's user edit and role change.

    public function test_a_contributor_cannot_revert_an_admins_user_edit_and_role_change_through_the_default_session(): void
    {
        [$target, $operation_id] = $this->admin_edits_a_user();
        (new \WP_User($target))->set_role('subscriber');
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $this->assertTrue(current_user_can('edit_posts'));

        $error = $this->session_refusal('default', 'rollback_not_owner');

        // Another user's operation is counted, not named.
        $this->assertSame(1, $error->get_error_data()['not_owned'] ?? null);
        $this->assertStringNotContainsString($operation_id, $error->get_error_message());
        $this->assertSame('new@example.test', $this->user_email($target));
        $this->assertSame(['subscriber'], $this->user_roles($target));
        $this->assertFalse($this->undone($operation_id));
    }

    public function test_a_contributor_cannot_revert_an_admins_user_edit_by_operation(): void
    {
        [$target, $operation_id] = $this->admin_edits_a_user();
        (new \WP_User($target))->set_role('subscriber');
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $this->assert_refused((new Rollback_Operation())->handle(['operation_id' => $operation_id]), 'rollback_not_owner');

        $this->assertSame('new@example.test', $this->user_email($target));
        $this->assertSame(['subscriber'], $this->user_roles($target));
        $this->assertFalse($this->undone($operation_id));
    }

    // Ownership below manage_options.

    public function test_an_editor_cannot_undo_an_authors_edit_of_a_post_the_editor_may_edit(): void
    {
        $author = self::factory()->user->create(['role' => 'author']);
        $editor = self::factory()->user->create(['role' => 'editor']);
        $post   = self::factory()->post->create(['post_author' => $author, 'post_title' => 'Original']);
        $theirs = $this->edit_post($author, $post, 'By the author');
        wp_set_current_user($editor);
        $this->assertTrue(current_user_can('edit_post', $post));

        $this->assert_refused((new Rollback_Operation())->handle(['operation_id' => $theirs]), 'rollback_not_owner');
        $this->session_refusal('default', 'rollback_not_owner');

        $this->assertSame('By the author', get_post($post)->post_title);
    }

    public function test_the_default_session_is_refused_whole_while_it_holds_another_users_operation(): void
    {
        $author = self::factory()->user->create(['role' => 'author']);
        (new \WP_User($author))->add_cap('edit_others_posts');
        $theirs = self::factory()->post->create(['post_author' => $this->admin, 'post_title' => 'Admin original']);
        $mine   = self::factory()->post->create(['post_author' => $author, 'post_title' => 'Author original']);
        $this->edit_post($this->admin, $theirs, 'Admin edit');
        $own = $this->edit_post($author, $mine, 'Author edit');
        wp_set_current_user($author);

        $this->session_refusal('default', 'rollback_not_owner');

        // All or nothing: not even the caller's own row was undone.
        $this->assertSame('Author edit', get_post($mine)->post_title);
        $this->assertSame('Admin edit', get_post($theirs)->post_title);
        $this->assertFalse($this->undone($own));
        // The caller can still undo their own operation on its own.
        $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $own])['restored']);
        $this->assertSame('Author original', get_post($mine)->post_title);
    }

    public function test_another_users_named_session_is_refused_with_the_rollback_code(): void
    {
        $author = self::factory()->user->create(['role' => 'author']);
        (new \WP_User($author))->add_cap('edit_others_posts');
        $post = self::factory()->post->create(['post_author' => $author, 'post_title' => 'Original']);
        $this->edit_post($this->admin, $post, 'Admin run', 'run-1');
        wp_set_current_user($author);

        $this->session_refusal('run-1', 'rollback_not_owner');

        $this->assertSame('Admin run', get_post($post)->post_title);
    }

    // Undoing one's own work.

    public function test_an_editor_restores_their_own_post_by_operation_and_by_session(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        $post   = self::factory()->post->create(['post_author' => $editor, 'post_title' => 'Original']);
        $first  = $this->edit_post($editor, $post, 'First');
        $this->edit_post($editor, $post, 'Second');

        $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $first])['restored']);
        $this->assertSame('Original', get_post($post)->post_title);

        $this->edit_post($editor, $post, 'Run 1', 'editor-run');
        $this->edit_post($editor, $post, 'Run 2', 'editor-run');
        $out = (new Rollback_Session())->handle(['session_id' => 'editor-run']);
        $this->assertSame(2, $out['restored_count']);
        $this->assertSame('Original', get_post($post)->post_title);
    }

    public function test_the_default_session_undoes_a_users_own_operations_when_it_holds_only_theirs(): void
    {
        $author = self::factory()->user->create(['role' => 'author']);
        $post   = self::factory()->post->create(['post_author' => $author, 'post_title' => 'Original']);
        $this->edit_post($author, $post, 'First');
        $this->edit_post($author, $post, 'Second');

        $out = (new Rollback_Session())->handle(['session_id' => 'default']);

        $this->assertSame(2, $out['restored_count']);
        $this->assertSame('Original', get_post($post)->post_title);
    }

    // Capability per restored object, for the current user.

    public function test_undoing_ones_own_user_edit_that_would_bring_back_a_lost_role_takes_promote_users(): void
    {
        $user = self::factory()->user->create(['role' => 'editor', 'user_email' => 'mine@example.test']);
        $operation_id = $this->edit_user($user, $user, 'changed@example.test');
        (new \WP_User($user))->set_role('author');
        wp_set_current_user($user);
        $this->assertTrue(current_user_can('edit_user', $user));
        $this->assertFalse(current_user_can('promote_user', $user));

        $error = $this->assert_refused((new Rollback_Operation())->handle(['operation_id' => $operation_id]), 'rollback_capability_required');

        $this->assertStringContainsString('promote_user', $error->get_error_message());
        $this->assertSame(['author'], $this->user_roles($user));
        $this->assertSame('changed@example.test', $this->user_email($user));
    }

    public function test_undoing_ones_own_user_edit_that_leaves_the_role_alone_needs_only_edit_user(): void
    {
        $user = self::factory()->user->create(['role' => 'author', 'user_email' => 'mine@example.test']);
        $operation_id = $this->edit_user($user, $user, 'changed@example.test');
        wp_set_current_user($user);

        $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $operation_id])['restored']);

        $this->assertSame('mine@example.test', $this->user_email($user));
        $this->assertSame(['author'], $this->user_roles($user));
    }

    public function test_an_editor_cannot_restore_a_plugin_package(): void
    {
        $editor       = self::factory()->user->create(['role' => 'editor']);
        $operation_id = $this->save_row($editor, 'package_install', ['type' => 'plugin', 'slug' => 'akismet', 'existed' => true]);
        wp_set_current_user($editor);

        $error = $this->assert_refused((new Rollback_Operation())->handle(['operation_id' => $operation_id]), 'rollback_capability_required');

        $this->assertStringContainsString('update_plugins', $error->get_error_message());
        $this->assertFalse($this->undone($operation_id));
    }

    public function test_a_type_that_names_no_capability_takes_manage_options(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        $rows   = [
            $this->save_row($editor, 'acf_options', ['post_id' => 'options', 'names' => ['x'], 'rows' => []]),
            $this->save_row($editor, 'elementor_global_variables', ['items' => []]),
            $this->save_row($editor, 'wpmcp_type_nobody_declared', []),
        ];
        wp_set_current_user($editor);

        foreach ($rows as $operation_id) {
            $error = $this->assert_refused((new Rollback_Operation())->handle(['operation_id' => $operation_id]), 'rollback_capability_required');
            $this->assertStringContainsString('manage_options', $error->get_error_message());
            $this->assertFalse($this->undone($operation_id));
        }
    }

    public function test_a_session_the_caller_owns_is_refused_whole_when_one_of_its_operations_needs_a_capability_they_lack(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        $post   = self::factory()->post->create(['post_author' => $editor, 'post_title' => 'Original']);
        $edit   = $this->edit_post($editor, $post, 'Edited', 'editor-mixed');
        $this->save_row($editor, 'package_install', ['type' => 'plugin', 'slug' => 'akismet', 'existed' => true], 'editor-mixed');
        wp_set_current_user($editor);

        $error = $this->session_refusal('editor-mixed', 'rollback_capability_required');

        $this->assertStringContainsString('update_plugins', $error->get_error_message());
        $this->assertSame('Edited', get_post($post)->post_title);
        $this->assertFalse($this->undone($edit));
    }

    // Administrators.

    public function test_an_admin_undoes_anyones_operation(): void
    {
        $author       = self::factory()->user->create(['role' => 'author']);
        $post         = self::factory()->post->create(['post_author' => $author, 'post_title' => 'Original']);
        $operation_id = $this->edit_post($author, $post, 'By the author');
        [$target, $user_operation] = $this->admin_edits_a_user();
        (new \WP_User($target))->set_role('subscriber');
        wp_set_current_user($this->admin);

        $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $operation_id])['restored']);
        $this->assertSame('Original', get_post($post)->post_title);
        $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $user_operation])['restored']);
        $this->assertSame('old@example.test', $this->user_email($target));
        $this->assertSame(['editor'], $this->user_roles($target));
    }

    public function test_an_admin_rolls_back_the_whole_default_session(): void
    {
        $author = self::factory()->user->create(['role' => 'author']);
        $post   = self::factory()->post->create(['post_author' => $author, 'post_title' => 'Original']);
        $this->edit_post($author, $post, 'By the author');
        [$target] = $this->admin_edits_a_user();
        wp_set_current_user($this->admin);

        $out = (new Rollback_Session())->handle(['session_id' => 'default']);

        $this->assertSame(2, $out['restored_count']);
        $this->assertSame('Original', get_post($post)->post_title);
        $this->assertSame('old@example.test', $this->user_email($target));
    }

    public function test_a_restore_made_on_no_ones_behalf_is_not_refused(): void
    {
        // Cron, WP-CLI without --user and a tool's own compensation run with
        // no user; every agent-facing path requires a logged-in caller.
        [$target, $operation_id] = $this->admin_edits_a_user();
        wp_set_current_user(0);

        $this->assertTrue(Rollback_Service::restore_operation($operation_id));
        $this->assertSame('old@example.test', $this->user_email($target));
    }

    // Multisite.

    public function test_on_a_network_a_site_admin_undoes_site_users_operations_but_not_a_super_admins(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only.');
        }
        $site_admin = self::factory()->user->create(['role' => 'administrator']);
        $author     = self::factory()->user->create(['role' => 'author']);
        $post       = self::factory()->post->create(['post_author' => $author, 'post_title' => 'Original']);
        $by_author  = $this->edit_post($author, $post, 'By the author');
        $page       = self::factory()->post->create(['post_author' => $this->admin, 'post_title' => 'Network original']);
        $by_super   = $this->edit_post($this->admin, $page, 'By the super admin');
        [$target, $user_operation] = $this->admin_edits_a_user();
        wp_set_current_user($site_admin);
        $this->assertTrue(current_user_can('manage_options'));
        $this->assertFalse(is_super_admin());

        $this->assert_refused((new Rollback_Operation())->handle(['operation_id' => $by_super]), 'rollback_not_owner');
        $this->assert_refused((new Rollback_Operation())->handle(['operation_id' => $user_operation]), 'rollback_not_owner');
        $this->session_refusal('default', 'rollback_not_owner');
        $this->assertSame('By the super admin', get_post($page)->post_title);
        $this->assertSame('new@example.test', $this->user_email($target));

        $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $by_author])['restored']);
        $this->assertSame('Original', get_post($post)->post_title);
    }

    public function test_on_a_network_a_site_admin_cannot_restore_a_users_network_wide_profile(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only.');
        }
        $site_admin = self::factory()->user->create(['role' => 'administrator']);
        $user       = self::factory()->user->create(['role' => 'author', 'user_email' => 'mine@example.test']);
        // An undo point of another site user's profile that the site admin
        // made: usermeta is network-wide, so editing it is a network write.
        $operation_id = $this->edit_user($site_admin, $user, 'changed@example.test');
        wp_set_current_user($site_admin);
        $this->assertFalse(current_user_can('edit_user', $user));

        $error = $this->assert_refused((new Rollback_Operation())->handle(['operation_id' => $operation_id]), 'rollback_capability_required');

        $this->assertStringContainsString('edit_user', $error->get_error_message());
        $this->assertSame('changed@example.test', $this->user_email($user));
    }

    public function test_on_a_network_the_super_admin_undoes_everything(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only.');
        }
        $site_admin = self::factory()->user->create(['role' => 'administrator']);
        $post       = self::factory()->post->create(['post_author' => $site_admin, 'post_title' => 'Original']);
        $by_site    = $this->edit_post($site_admin, $post, 'By the site admin');
        [$target, $user_operation] = $this->admin_edits_a_user();
        (new \WP_User($target))->set_role('subscriber');
        wp_set_current_user($this->admin);

        $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $by_site])['restored']);
        $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $user_operation])['restored']);
        $this->assertSame('Original', get_post($post)->post_title);
        $this->assertSame('old@example.test', $this->user_email($target));
        $this->assertSame(['editor'], $this->user_roles($target));
    }

    // What the screens and list-operations offer.

    public function test_list_operations_offers_undo_only_where_the_caller_may_undo_by_ownership(): void
    {
        $author    = self::factory()->user->create(['role' => 'author']);
        $post      = self::factory()->post->create(['post_author' => $author, 'post_title' => 'Original']);
        $by_author = $this->edit_post($author, $post, 'By the author');
        $page      = self::factory()->post->create(['post_author' => $this->admin, 'post_title' => 'Admin original']);
        $by_admin  = $this->edit_post($this->admin, $page, 'By the admin');
        $viewer    = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($viewer);

        $available = array_column((new List_Operations())->handle(['limit' => 50])['operations'], 'rollback_available', 'operation_id');

        $this->assertTrue($available[ $by_author ]);
        // On a network the other administrator is a super admin, whose
        // operations only a super admin undoes.
        $this->assertSame(! is_multisite(), $available[ $by_admin ]);

        ob_start();
        (new History_Page())->render();
        $html = (string) ob_get_clean();
        $this->assertSame(is_multisite() ? 1 : 2, substr_count($html, 'class="button wpmcp-restore"'));
    }
}
