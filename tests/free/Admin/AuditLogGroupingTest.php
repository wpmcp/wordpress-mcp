<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\MCP\Request_Log;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Backup\Backup_Job_Store;

/**
 * Issue #1126: one row per call, backup and restore jobs listed, times in
 * the site timezone, object titles, a plain domain for theme writes,
 * dropdown filters, an Undo header, and a Restore that confirms and shows a
 * refusal.
 */
class AuditLogGroupingTest extends \WP_UnitTestCase
{
    private int $admin = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        delete_option(Backup_Job_Store::OPTION);
        delete_option(Request_Log::OPTION);
        $_GET        = [];
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'display_name' => 'Ada Admin' ]);
        wp_set_current_user($this->admin);
        update_option('timezone_string', 'Asia/Karachi');
    }

    protected function tearDown(): void
    {
        $_GET = [];
        delete_option(Backup_Job_Store::OPTION);
        delete_option(Request_Log::OPTION);
        Request_Log::set_clock_for_tests(null);
        update_option('timezone_string', '');
        parent::tearDown();
    }

    private function save(string $op, string $tool, int $object_id, string $hash = 'a', string $session = 'sess'): void
    {
        Snapshot_Store::save($op, $session, [ 'object_type' => 'post', 'object_id' => $object_id, 'data' => [ 'post' => null, 'meta' => [] ] ], $tool, str_repeat($hash, 64));
    }

    private function render(): string
    {
        ob_start();
        (new Audit_Log_Page())->render();
        return (string) ob_get_clean();
    }

    public function test_a_call_that_wrote_several_operations_is_one_row_with_one_restore(): void
    {
        $this->save('op-a1', 'switch-theme', 0, 'a');
        $this->save('op-a2', 'switch-theme', 0, 'a');
        $this->save('op-b', 'update-post', 5, 'b');

        $html = $this->render();

        preg_match_all('#class="button wpmcp-restore" data-op="([^"]+)"#', $html, $m);
        $this->assertSame([ 'op-b', 'op-a2,op-a1' ], $m[1], 'newest first, the two-op call grouped');
        $this->assertStringContainsString('2 changes', $html);
    }

    public function test_times_are_shown_in_the_site_timezone_on_both_tabs(): void
    {
        $this->save('op-1', 'update-post', 5);
        $row = Snapshot_Store::get_by_operation('op-1');

        $html = $this->render();
        $this->assertStringContainsString(wp_date('Y-m-d H:i:s', strtotime($row['created_at'] . ' UTC')) . ' Asia/Karachi', $html);
        $this->assertStringContainsString('<th>Undo</th>', $html);

        Request_Log::set_clock_for_tests(1700000000);
        Request_Log::record([ 'tool' => 'wpmcp/get-page', 'ok' => true ]);
        $_GET['tab'] = 'requests';
        $requests    = $this->render();
        $this->assertStringContainsString(wp_date('Y-m-d H:i:s', 1700000000) . ' Asia/Karachi', $requests);
        $this->assertStringNotContainsString(gmdate('Y-m-d H:i:s', 1700000000) . '<', $requests);
    }

    public function test_what_shows_the_title_and_theme_writes_read_themes(): void
    {
        $post = self::factory()->post->create([ 'post_title' => 'Home template' ]);
        $this->save('op-1', 'set-template-conditions', $post);
        $this->save('op-2', 'switch-theme', 0, 'c');

        $html = $this->render();

        $this->assertStringContainsString('set-template-conditions: Home template (#' . $post . ')', $html);
        $this->assertMatchesRegularExpression('#<td class="column-domain">Themes</td>#', $html);
    }

    public function test_tool_and_user_filters_are_dropdowns_of_values_in_the_log(): void
    {
        $this->save('op-1', 'update-post', 5);
        $this->save('op-2', 'delete-post', 6, 'd');

        $html = $this->render();

        $this->assertMatchesRegularExpression('#<select name="tool_name"[^>]*>.*<option value="delete-post"[^>]*>delete-post</option>.*<option value="update-post"#s', $html);
        $this->assertMatchesRegularExpression('#<select name="user_id"[^>]*>.*<option value="' . $this->admin . '"[^>]*>Ada Admin</option>#s', $html);
        $this->assertStringNotContainsString('<input type="text" name="tool_name"', $html);
        $this->assertStringNotContainsString('<input type="number" name="user_id"', $html);

        Request_Log::record([ 'tool' => 'wpmcp/get-page', 'ok' => true ]);
        $_GET['tab'] = 'requests';
        $requests    = $this->render();
        $this->assertMatchesRegularExpression('#<select name="tool"[^>]*>.*<option value="wpmcp/get-page"#s', $requests);
        $this->assertMatchesRegularExpression('#<select name="user_id"[^>]*>.*Ada Admin</option>#s', $requests);
    }

    public function test_backup_and_restore_jobs_are_listed_with_status_and_time(): void
    {
        $backup  = Backup_Job_Store::create('database', 'database');
        $restore = Backup_Job_Store::create('restore', 'database');
        Backup_Job_Store::update((int) $restore['id'], [ 'status' => 'failed', 'error' => 'The import failed.' ]);

        $html = $this->render();

        $this->assertStringContainsString('Backup and restore jobs', $html);
        $this->assertMatchesRegularExpression('#<tr class="wpmcp-job" data-job="' . $restore['id'] . '">.*?Restore.*?failed.*?The import failed\.#s', $html);
        $this->assertMatchesRegularExpression('#<tr class="wpmcp-job" data-job="' . $backup['id'] . '">.*?Backup.*?queued#s', $html);
    }

    public function test_restore_confirms_naming_the_object_and_shows_the_refusal(): void
    {
        $post = self::factory()->post->create([ 'post_title' => 'About us' ]);
        $this->save('op-1', 'update-post', $post);

        $html = $this->render();

        $this->assertMatchesRegularExpression('#class="button wpmcp-restore" data-op="op-1"[^>]*data-label="update-post: About us \(\#' . $post . '\)"#', $html);
        $this->assertStringContainsString('window.confirm(', $html);
        $this->assertStringContainsString('res.data.message', $html, 'a refusal shows its message');
        $this->assertStringNotContainsString(".then(function () { window.location.reload(); });\n        });\n\n        // Undo session", $html, 'the reload no longer ignores the response');
    }
}
