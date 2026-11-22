<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Restore_Controller;
use WPMCP\MCP\Request_Log;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\Update_Blocks;

/**
 * The Audit Log's Undo session button (issue #1150): one AJAX call that runs
 * the same rollback-session path the agent uses, behind the management
 * capability and its own nonce.
 *
 * @group ajax
 */
class UndoSessionAjaxTest extends \WP_Ajax_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Request_Log::clear();
        add_filter('pre_http_request', [$this, 'stub_version_check'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'stub_version_check'], 10);
        $_POST = [];
        parent::tearDown();
    }

    /** Same stub as RestoreControllerAjaxTest: admin_init must not reach api.wordpress.org. */
    public function stub_version_check($preempt, $parsed_args, $url)
    {
        if (false === strpos($url, 'api.wordpress.org') || false === strpos($url, '-check')) {
            return $preempt;
        }
        return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
    }

    private const V1 = '<!-- wp:paragraph --><p>V1</p><!-- /wp:paragraph -->';

    /** @return array{0: int[], 1: string[]} post ids and operation ids of $n writes in $session. */
    private function session_of(string $session, int $n): array
    {
        $ids = [];
        $ops = [];
        for ($i = 0; $i < $n; $i++) {
            $id    = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => 'V0' ]);
            $out   = (new Update_Blocks())->handle([ 'id' => $id, 'blocks' => self::V1, 'session_id' => $session ]);
            $ids[] = $id;
            $ops[] = $out['operation_id'];
        }
        return [ $ids, $ops ];
    }

    /** @return array<string, mixed> the decoded JSON body */
    private function call(string $session, ?string $nonce): array
    {
        $_POST['session_id'] = $session;
        if (null === $nonce) {
            unset($_POST['nonce']);
        } else {
            $_POST['nonce'] = $nonce;
        }
        try {
            $this->_handleAjax(Restore_Controller::SESSION_ACTION);
        } catch (\WPAjaxDieContinueException $e) {
        } catch (\WPAjaxDieStopException $e) {
        }
        $response = json_decode((string) $this->_last_response, true);
        $this->assertIsArray($response, 'Expected a JSON response body.');
        return $response;
    }

    public function test_undoes_every_write_of_the_session_and_marks_each_row_undone(): void
    {
        $this->_setRole('administrator');
        [ $ids, $ops ] = $this->session_of('sess-a', 3);

        $response = $this->call('sess-a', wp_create_nonce(Restore_Controller::SESSION_ACTION));

        $this->assertTrue($response['success']);
        $this->assertSame(3, $response['data']['restored_count']);
        foreach ($ids as $id) {
            $this->assertSame('V0', get_post($id)->post_content);
        }
        foreach ($ops as $op) {
            $this->assertNotNull(Snapshot_Store::rolled_back_at(Snapshot_Store::get_by_operation($op)), "{$op} should show as undone");
        }
    }

    public function test_result_matches_rollback_session_for_an_identical_session(): void
    {
        $this->_setRole('administrator');
        [ , $ops_a ] = $this->session_of('sess-a', 2);
        [ , $ops_b ] = $this->session_of('sess-b', 2);
        // One write of each already undone: both paths skip it the same way.
        (new Rollback_Operation())->handle([ 'operation_id' => $ops_a[0] ]);
        (new Rollback_Operation())->handle([ 'operation_id' => $ops_b[0] ]);

        $tool     = (new Rollback_Session())->handle([ 'session_id' => 'sess-b' ]);
        $response = $this->call('sess-a', wp_create_nonce(Restore_Controller::SESSION_ACTION));

        $this->assertTrue($response['success']);
        $this->assertSame($tool['restored_count'], $response['data']['restored_count']);
        $this->assertSame(
            str_replace('sess-b', 'sess-a', implode("\n", $tool['warnings'])),
            implode("\n", $response['data']['warnings'])
        );
    }

    public function test_edit_lock_is_refused_with_the_tool_message_and_nothing_changes(): void
    {
        $this->_setRole('administrator');
        [ $ids ] = $this->session_of('sess-a', 2);
        $other   = self::factory()->user->create([ 'role' => 'editor' ]);
        update_post_meta($ids[1], '_edit_lock', time() . ':' . $other);

        $tool_message = '';
        try {
            (new Rollback_Session())->handle([ 'session_id' => 'sess-a' ]);
        } catch (\WPMCP\Safety\Mutation_Failed $e) {
            $tool_message = $e->getMessage();
        }
        $this->assertNotSame('', $tool_message, 'rollback-session itself refuses a locked session');

        $response = $this->call('sess-a', wp_create_nonce(Restore_Controller::SESSION_ACTION));

        $this->assertFalse($response['success']);
        // The message names how many seconds ago the lock was refreshed,
        // which can tick over between the two calls.
        $seconds = static fn (string $m): string => (string) preg_replace('/\(\d+ seconds? ago\)/', '(N seconds ago)', $m);
        $this->assertSame($seconds($tool_message), $seconds($response['data']['message']));
        foreach ($ids as $id) {
            $this->assertSame(self::V1, get_post($id)->post_content, 'no partial undo');
        }
    }

    public function test_bad_nonce_is_forbidden_and_nothing_changes(): void
    {
        $this->_setRole('administrator');
        [ $ids ] = $this->session_of('sess-a', 2);

        $response = $this->call('sess-a', 'not-a-valid-nonce');

        $this->assertFalse($response['success']);
        $this->assertSame(self::V1, get_post($ids[0])->post_content);
        $this->assertSame(self::V1, get_post($ids[1])->post_content);
    }

    public function test_single_restore_nonce_does_not_open_the_session_undo(): void
    {
        $this->_setRole('administrator');
        [ $ids ] = $this->session_of('sess-a', 1);

        $response = $this->call('sess-a', wp_create_nonce('wpmcp_restore'));

        $this->assertFalse($response['success']);
        $this->assertSame(self::V1, get_post($ids[0])->post_content);
    }

    public function test_user_without_management_access_is_forbidden_and_nothing_changes(): void
    {
        $this->_setRole('administrator');
        [ $ids ] = $this->session_of('sess-a', 2);
        $this->_setRole('editor');

        $response = $this->call('sess-a', wp_create_nonce(Restore_Controller::SESSION_ACTION));

        $this->assertFalse($response['success']);
        $this->assertSame(self::V1, get_post($ids[0])->post_content);
    }

    public function test_the_catch_all_session_is_never_undone_from_the_screen(): void
    {
        $this->_setRole('administrator');
        [ $ids ] = $this->session_of('default', 1);

        $response = $this->call('default', wp_create_nonce(Restore_Controller::SESSION_ACTION));

        $this->assertFalse($response['success']);
        $this->assertSame(self::V1, get_post($ids[0])->post_content);
    }

    public function test_the_undo_is_recorded_in_the_request_log(): void
    {
        $this->_setRole('administrator');
        $this->session_of('sess-a', 2);

        $this->call('sess-a', wp_create_nonce(Restore_Controller::SESSION_ACTION));

        $rows = Request_Log::list();
        $this->assertNotEmpty($rows);
        $last = end($rows);
        $this->assertSame('wpmcp/rollback-session', $last['tool']);
        $this->assertSame('wp-admin', $last['client']);
        $this->assertTrue($last['ok']);
    }
}
