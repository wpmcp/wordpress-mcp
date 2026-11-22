<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\Admin\Restore_Controller;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #1150: Mutations rows name their session, the session filter narrows
 * the table to it, and a named session carries one Undo session button.
 */
class AuditLogSessionColumnTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $_GET = [];
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    private function save(string $op, string $session): void
    {
        Snapshot_Store::save($op, $session, [ 'object_type' => 'post', 'object_id' => 1, 'data' => [ 'post' => null, 'meta' => [] ] ], 'update-post', hash('sha256', (string) $op));
    }

    private function render(): string
    {
        ob_start();
        (new Audit_Log_Page())->render();
        return (string) ob_get_clean();
    }

    public function test_rows_show_their_session_with_a_filter_link(): void
    {
        $this->save('op-1', 'sess-a');
        $this->save('op-2', 'sess-b');

        $html = $this->render();

        $this->assertStringContainsString('<th>Session</th>', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*session_id=sess-a[^"]*"#', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*session_id=sess-b[^"]*"#', $html);
    }

    public function test_session_filter_lists_only_that_session(): void
    {
        $this->save('op-1', 'sess-a');
        $this->save('op-2', 'sess-b');
        $this->save('op-3', 'sess-a');
        $_GET['session_id'] = 'sess-a';

        $html = $this->render();

        preg_match_all('#data-op="([^"]+)"#', $html, $m);
        $this->assertSame([ 'op-3', 'op-1' ], $m[1]);
        $this->assertMatchesRegularExpression('#name="session_id"[^>]*value="sess-a"#', $html);
    }

    public function test_named_session_has_one_undo_button_naming_its_undoable_count(): void
    {
        $this->save('op-1', 'sess-a');
        $this->save('op-2', 'sess-a');
        $this->save('op-3', 'sess-a');
        Snapshot_Store::claim_rollback('op-1');

        $html = $this->render();

        $this->assertSame(1, preg_match_all('#class="button wpmcp-undo-session"[^>]*data-session="sess-a"#', $html));
        $this->assertMatchesRegularExpression('#data-session="sess-a"[^>]*data-count="2"#', $html);
        $this->assertStringContainsString('data-nonce="', $html);
        $this->assertStringContainsString("action', '" . Restore_Controller::SESSION_ACTION . "'", $html);
        $this->assertStringContainsString('confirm(', $html);
    }

    public function test_catch_all_and_fully_undone_sessions_get_no_undo_button(): void
    {
        $this->save('op-1', 'default');
        $this->save('op-2', 'sess-done');
        Snapshot_Store::claim_rollback('op-2');

        $html = $this->render();

        $this->assertStringNotContainsString('data-session="default"', $html);
        $this->assertStringNotContainsString('data-session="sess-done"', $html);
    }
}
