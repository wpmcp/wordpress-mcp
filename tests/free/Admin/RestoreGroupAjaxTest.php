<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Update_Blocks;

/**
 * Issue #1126: the grouped Restore undoes every operation of the call,
 * newest first, and a refused restore answers with the tool's message
 * instead of looking like a success.
 *
 * @group ajax
 */
class RestoreGroupAjaxTest extends \WP_Ajax_UnitTestCase
{
    private const V1 = '<!-- wp:paragraph --><p>V1</p><!-- /wp:paragraph -->';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        add_filter('pre_http_request', [ $this, 'stub' ], 10, 3);
        $this->_setRole('administrator');
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [ $this, 'stub' ], 10);
        $_POST = [];
        parent::tearDown();
    }

    public function stub($preempt, $args, $url)
    {
        if (false === strpos($url, 'api.wordpress.org') || false === strpos($url, '-check')) {
            return $preempt;
        }
        return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
    }

    private function write(): array
    {
        $id  = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => 'V0' ]);
        $out = (new Update_Blocks())->handle([ 'id' => $id, 'blocks' => self::V1, 'session_id' => 's' ]);
        return [ $id, $out['operation_id'] ];
    }

    private function call(string $ops): array
    {
        $_POST['operation_id'] = $ops;
        $_POST['nonce']        = wp_create_nonce('wpmcp_restore');
        try {
            $this->_handleAjax('wpmcp_restore');
        } catch (\WPAjaxDieContinueException $e) {
        } catch (\WPAjaxDieStopException $e) {
        }
        $response = json_decode((string) $this->_last_response, true);
        $this->assertIsArray($response);
        return $response;
    }

    public function test_a_grouped_restore_undoes_every_operation_of_the_call(): void
    {
        [ $a, $op_a ] = $this->write();
        [ $b, $op_b ] = $this->write();

        $response = $this->call($op_b . ',' . $op_a);

        $this->assertTrue($response['success']);
        $this->assertTrue($response['data']['restored']);
        $this->assertSame(2, $response['data']['restored_count']);
        $this->assertSame('V0', get_post($a)->post_content);
        $this->assertSame('V0', get_post($b)->post_content);
    }

    public function test_a_refused_restore_answers_with_the_tool_message(): void
    {
        [ $id, $op ] = $this->write();
        $other = self::factory()->user->create([ 'role' => 'editor' ]);
        update_post_meta($id, '_edit_lock', time() . ':' . $other);

        $response = $this->call($op);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('open in the editor', (string) $response['data']['message']);
        $this->assertSame(self::V1, get_post($id)->post_content);
    }

    public function test_an_unknown_operation_is_an_error_not_a_success(): void
    {
        $response = $this->call('no-such-op');

        $this->assertFalse($response['success']);
        $this->assertNotSame('', (string) $response['data']['message']);
    }
}
