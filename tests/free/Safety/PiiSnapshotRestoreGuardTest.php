<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #66: a form submission is personal data, so the forms adapters gate
 * reading and deleting one behind an administrator-only capability. That gate
 * is one-way unless the UNDO path honours it too: wpmcp/rollback-operation and
 * wpmcp/rollback-session are registered at edit_posts, and a delete leaves a
 * verbatim plaintext copy of the submission in wpmcp_snapshots, so without
 * this an Editor could resurrect a submission they are not allowed to read.
 *
 * The gate lives in Rollback_Service rather than in any one adapter because
 * the snapshot outlives the adapter call, and it is keyed on the snapshotted
 * post type so the same protection covers every entry-bearing adapter.
 */
class PiiSnapshotRestoreGuardTest extends \WP_UnitTestCase
{
    private int $entry_id;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_post_type('flamingo_inbound', [ 'public' => false ]);

        $this->entry_id = self::factory()->post->create([
            'post_type'    => 'flamingo_inbound',
            'post_title'   => 'ada@example.test',
            'post_content' => 'My phone number is 555-0100',
        ]);
    }

    protected function tearDown(): void
    {
        unregister_post_type('flamingo_inbound');
        parent::tearDown();
    }

    /** Delete the entry through Safe_Mutation so a real snapshot row exists. */
    private function delete_entry_with_snapshot(?int $user = null): string
    {
        if (null === $user) {
            wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
            wpmcp_test_grant_network_admin(get_current_user_id());
        } else {
            wp_set_current_user($user);
        }
        $id  = $this->entry_id;
        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => 'pii-66',
                'tool_name'   => 'contactform7-write',
                'args'        => [ 'operation' => 'delete-entry' ],
            ],
            static fn () => wp_delete_post($id, true)
        );

        $this->assertNull(get_post($id));
        return $out['operation_id'];
    }

    public function test_an_editor_cannot_resurrect_a_submission_they_may_not_read(): void
    {
        // The editor's own write (only one's own are undone below
        // manage_options), so the PII guard is
        // what refuses.
        $operation_id = $this->delete_entry_with_snapshot(self::factory()->user->create([ 'role' => 'editor' ]));

        $restored = Rollback_Service::restore_operation($operation_id);

        $this->assertFalse($restored, 'A PII snapshot must not be restorable at edit_posts');
        $this->assertNull(get_post($this->entry_id), 'The submission stays deleted');
        $this->assertNotEmpty(Rollback_Service::take_warnings(), 'The refusal must be visible, not silent');
    }

    public function test_an_administrator_can_still_roll_the_deletion_back(): void
    {
        $operation_id = $this->delete_entry_with_snapshot();

        // Same administrator who deleted it: reversibility is the whole point
        // of snapshotting, so the guard must not break it for the people the
        // adapter's own capability gate already admits.
        $this->assertTrue(Rollback_Service::restore_operation($operation_id));

        $restored = get_post($this->entry_id);
        $this->assertNotNull($restored);
        $this->assertSame('flamingo_inbound', $restored->post_type);
    }

    public function test_a_session_rollback_is_refused_whole_for_a_pii_snapshot_it_may_not_restore(): void
    {
        $ordinary = self::factory()->post->create([ 'post_content' => 'before' ]);
        // The editor starts the session (it is theirs to roll back, issue
        // #450) and their own entry delete then lands in it.
        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        wp_set_current_user($editor);
        Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $ordinary,
                'session_id'  => 'pii-66',
                'tool_name'   => 'update-post',
                'args'        => [],
            ],
            static fn () => wp_update_post([ 'ID' => $ordinary, 'post_content' => 'after' ])
        );
        $this->delete_entry_with_snapshot($editor);

        try {
            Rollback_Service::restore_session('pii-66');
            $this->fail('The session was rolled back past a submission the editor may not read.');
        } catch (\WPMCP\Safety\Post_Write_Refused $e) {
            $this->assertSame(Rollback_Service::REFUSED_CAPABILITY, $e->error()->get_error_code());
            $this->assertStringContainsString('edit_users', $e->error()->get_error_message());
        }

        // All or nothing.
        $this->assertSame('after', get_post($ordinary)->post_content, 'Nothing is unwound');
        $this->assertNull(get_post($this->entry_id), 'The submission is not resurrected');
    }

    public function test_an_ordinary_post_snapshot_is_not_gated(): void
    {
        $post = self::factory()->post->create([ 'post_content' => 'before' ]);
        // The editor's own edit: below manage_options only one's own
        // operations are undone.
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post,
                'session_id'  => 'plain-66',
                'tool_name'   => 'update-post',
                'args'        => [],
            ],
            static fn () => wp_update_post([ 'ID' => $post, 'post_content' => 'after' ])
        );

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('before', get_post($post)->post_content);
    }

    public function test_a_site_can_declare_its_own_pii_post_types(): void
    {
        $filter = static function (array $map): array {
            $map['post'] = 'manage_options';
            return $map;
        };
        add_filter('wpmcp_pii_snapshot_capabilities', $filter);

        $post = self::factory()->post->create([ 'post_content' => 'before' ]);
        // The editor's own edit, so the declared capability is what refuses.
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post,
                'session_id'  => 'custom-66',
                'tool_name'   => 'update-post',
                'args'        => [],
            ],
            static fn () => wp_update_post([ 'ID' => $post, 'post_content' => 'after' ])
        );

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('after', get_post($post)->post_content);
    }
}
