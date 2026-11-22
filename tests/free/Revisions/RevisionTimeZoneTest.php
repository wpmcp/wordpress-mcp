<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Revisions;

use WPMCP\Tools\Content\Diff_Revisions;
use WPMCP\Tools\Revisions\List_Revisions;

/**
 * Issue #1199: on a site with a non-UTC timezone, list-revisions and
 * diff-revisions report the same revision's time the same way: UTC, with
 * an explicit offset.
 */
final class RevisionTimeZoneTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        update_option('timezone_string', 'Asia/Karachi');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        update_option('timezone_string', '');
        parent::tearDown();
    }

    public function test_both_tools_give_the_same_revision_the_same_utc_time(): void
    {
        $post = self::factory()->post->create([ 'post_content' => 'one' ]);
        wp_update_post([ 'ID' => $post, 'post_content' => 'two' ]);
        wp_update_post([ 'ID' => $post, 'post_content' => 'three' ]);

        $listed = (new List_Revisions())->handle([ 'post_id' => $post ])['revisions'];
        $this->assertGreaterThanOrEqual(2, count($listed));
        $newest = $listed[0];
        $older  = $listed[1];

        $diff = (new Diff_Revisions())->handle([ 'from_revision_id' => $older['revision_id'], 'to_revision_id' => $newest['revision_id'] ]);

        $revision = get_post($older['revision_id']);
        $this->assertSame((string) $revision->post_date_gmt, $older['date_gmt']);
        $this->assertStringEndsWith('+00:00', $older['date_iso']);
        $this->assertSame($older['date_iso'], $diff['from']['date_iso']);
        $this->assertSame($newest['date_iso'], $diff['to']['date_iso']);
        $this->assertSame(gmdate('c', (int) strtotime($revision->post_date_gmt . ' UTC')), $older['date_iso']);
        $this->assertStringEndsWith('+00:00', $diff['from']['modified_iso']);
        $this->assertNotSame($older['date'], $older['date_gmt'], 'date stays the site-local time, now told apart');
    }
}
