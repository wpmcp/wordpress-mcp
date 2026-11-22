<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Comments;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Comments\Delete_Comment;

/**
 * Issue #1200: wp_delete_comment() hands a deleted comment's direct replies
 * to its parent. Undoing the delete brings the comment back under a new id
 * and points those replies at it again, unless one was moved since.
 */
final class DeleteCommentRepliesUndoTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_delete_comment', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_delete_comment', '__return_true');
        parent::tearDown();
    }

    /** The newest comment on $post whose content is $content. */
    private function find(int $post, string $content): \WP_Comment
    {
        foreach (get_comments([ 'post_id' => $post, 'status' => 'all', 'orderby' => 'comment_ID', 'order' => 'DESC' ]) as $comment) {
            if ($content === $comment->comment_content) {
                return $comment;
            }
        }
        $this->fail("No comment with content {$content}");
    }

    public function test_undo_relinks_the_replies_the_delete_reparented(): void
    {
        $post   = self::factory()->post->create();
        $top    = self::factory()->comment->create([ 'comment_post_ID' => $post, 'comment_content' => 'top' ]);
        $parent = self::factory()->comment->create([ 'comment_post_ID' => $post, 'comment_parent' => $top, 'comment_content' => 'parent' ]);
        $reply  = self::factory()->comment->create([ 'comment_post_ID' => $post, 'comment_parent' => $parent, 'comment_content' => 'reply' ]);
        $second = self::factory()->comment->create([ 'comment_post_ID' => $post, 'comment_parent' => $parent, 'comment_content' => 'second reply' ]);

        $out = (new Delete_Comment())->handle([ 'id' => $parent, 'confirm' => true ]);
        clean_comment_cache([ $reply, $second ]);
        $this->assertSame($top, (int) get_comment($reply)->comment_parent, 'WordPress handed the reply to the grandparent');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));

        $restored = $this->find($post, 'parent');
        clean_comment_cache([ $reply, $second ]);
        $this->assertNotSame($parent, (int) $restored->comment_ID, 'a new id, as the tool warns');
        $this->assertSame((int) $restored->comment_ID, (int) get_comment($reply)->comment_parent);
        $this->assertSame((int) $restored->comment_ID, (int) get_comment($second)->comment_parent);
    }

    public function test_a_reply_moved_since_the_delete_is_left_where_it_is(): void
    {
        $post   = self::factory()->post->create();
        $parent = self::factory()->comment->create([ 'comment_post_ID' => $post, 'comment_content' => 'parent' ]);
        $reply  = self::factory()->comment->create([ 'comment_post_ID' => $post, 'comment_parent' => $parent, 'comment_content' => 'reply' ]);
        $other  = self::factory()->comment->create([ 'comment_post_ID' => $post, 'comment_content' => 'other' ]);

        $out = (new Delete_Comment())->handle([ 'id' => $parent, 'confirm' => true ]);
        wp_update_comment([ 'comment_ID' => $reply, 'comment_parent' => $other ]);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));

        clean_comment_cache($reply);
        $this->assertSame($other, (int) get_comment($reply)->comment_parent);
    }
}
