<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Refusal;
use WPMCP\MCP\Refusal_Result;
use WPMCP\MCP\Unavailable;

/**
 * The one refusal envelope (issue #628): every refusal a tool gives, a
 * WP_Error or the error envelope of a dispatcher pair, is a tool error with
 * isError true that keeps its code and data three ways: a line a model
 * reads, the envelope as JSON text, and the envelope under
 * _meta["com.wpmcp-pro/unavailable"]. RefusalEnvelopeWireTest checks that
 * both transports send exactly this; here is the shape itself.
 */
final class RefusalEnvelopeTest extends \WP_UnitTestCase
{
    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function meta(array $result): array
    {
        return $result['_meta'][ Refusal_Result::META_KEY ];
    }

    public function test_a_refusal_with_the_contract_carries_one_envelope_three_ways(): void
    {
        $error = ( new Unavailable('plugin_inactive', 'WooCommerce is installed but not active on this site.', 'A site administrator activates it in wp-admin > Plugins, then retries.', [ 'admin_url' => 'https://example.org/wp-admin/plugins.php' ]) )
            ->to_wp_error('', [ 'plugin' => 'woocommerce' ]);

        $result = Refusal_Result::for_outcome($error);

        $this->assertIsArray($result);
        $this->assertTrue($result['isError']);
        $this->assertArrayNotHasKey('structuredContent', $result, 'An error result carries no structuredContent: clients validate it against the outputSchema.');
        $this->assertCount(2, $result['content']);
        $this->assertSame(
            'Unavailable (plugin_inactive): WooCommerce is installed but not active on this site. A site administrator activates it in wp-admin > Plugins, then retries. Docs: https://wpmcp-pro.com/docs.html#unavailable-plugin-inactive',
            $result['content'][0]['text']
        );

        $envelope = self::meta($result);
        $this->assertSame([ 'code', 'reason', 'message', 'action', 'docs_url', 'retryable', 'hidden', 'data' ], array_keys($envelope));
        $this->assertSame('plugin_inactive', $envelope['code']);
        $this->assertSame('plugin_inactive', $envelope['reason']);
        $this->assertSame([ 'who' => 'site_admin', 'text' => 'A site administrator activates it in wp-admin > Plugins, then retries.', 'admin_url' => 'https://example.org/wp-admin/plugins.php' ], $envelope['action']);
        $this->assertSame('after_human_action', $envelope['retryable']);
        $this->assertTrue($envelope['hidden']);
        $this->assertSame([ 'plugin' => 'woocommerce' ], $envelope['data'], 'The HTTP status hint and the contract are not repeated in data.');

        $this->assertSame('text', $result['content'][1]['type']);
        $this->assertSame(json_decode((string) wp_json_encode($envelope), true), json_decode($result['content'][1]['text'], true));
    }

    public function test_a_rate_limit_carries_retry_after_on_the_envelope(): void
    {
        $error = new \WP_Error('wpmcp_rate_limited', 'Rate limit exceeded for "wpmcp/x". Retry after 12 second(s).', [ 'retry_after' => 12, 'remaining' => 0 ]);
        ( new Unavailable('rate_limited', 'Rate limit exceeded.', 'Wait 12 second(s), then retry.', [ 'retry_after' => 12 ]) )->attach($error);

        $envelope = self::meta((array) Refusal_Result::for_outcome($error));

        $this->assertSame('wpmcp_rate_limited', $envelope['code']);
        $this->assertSame('rate_limited', $envelope['reason']);
        $this->assertSame('later', $envelope['retryable']);
        $this->assertSame(12, $envelope['retry_after']);
        $this->assertSame([ 'retry_after' => 12, 'remaining' => 0 ], $envelope['data']);
    }

    public function test_a_refusal_without_the_contract_keeps_its_code_and_data(): void
    {
        $error  = new \WP_Error('unfiltered_html_required', 'This page holds markup WordPress saves only for users with the unfiltered_html capability.', [ 'status' => 403 ]);
        $result = (array) Refusal_Result::for_outcome($error);

        $this->assertTrue($result['isError']);
        $this->assertSame($error->get_error_message(), $result['content'][0]['text'], 'No reason, so the line is the message as written.');
        $envelope = self::meta($result);
        $this->assertSame([ 'code', 'message', 'data' ], array_keys($envelope));
        $this->assertSame('unfiltered_html_required', $envelope['code']);
        $this->assertEquals(new \stdClass(), $envelope['data'], 'Empty data is still a JSON object.');
        $this->assertSame('{"code":"unfiltered_html_required","message":"This page holds markup WordPress saves only for users with the unfiltered_html capability.","data":{}}', $result['content'][1]['text']);
    }

    public function test_a_permission_denial_inside_execute_keeps_the_reason_it_wraps(): void
    {
        $inner = ( new Unavailable('identity_scope', 'Outside the identity.', 'Widen it.') )->to_wp_error();
        $error = new \WP_Error('ability_invalid_permissions', $inner->get_error_message(), [ 'refusal' => 'identity_scope' ] + (array) $inner->get_error_data());

        $envelope = self::meta((array) Refusal_Result::for_outcome($error));

        $this->assertSame('ability_invalid_permissions', $envelope['code']);
        $this->assertSame('identity_scope', $envelope['reason']);
        $this->assertSame([ 'refusal' => 'identity_scope' ], $envelope['data']);
    }

    public function test_a_dispatcher_error_envelope_is_a_refusal(): void
    {
        $outcome = [
            'integration' => 'theme',
            'error'       => [
                'code'    => 'plugin_inactive',
                'message' => 'Give is installed but not active on this site, so op "give-list-forms" cannot run.',
                'data'    => [ 'plugin' => 'give', 'operation' => 'give-list-forms' ],
            ],
        ];

        $result = Refusal_Result::for_outcome($outcome);

        $this->assertIsArray($result);
        $this->assertTrue($result['isError']);
        $this->assertSame($outcome['error']['message'], $result['content'][0]['text']);
        $this->assertSame(
            [ 'code' => 'plugin_inactive', 'message' => $outcome['error']['message'], 'data' => [ 'plugin' => 'give', 'operation' => 'give-list-forms', 'integration' => 'theme' ] ],
            self::meta($result)
        );
    }

    public function test_a_dispatcher_envelope_with_the_contract_names_its_reason(): void
    {
        $contract = ( new Unavailable('opt_in_required', '', 'A site administrator switches it on.', [ 'hidden' => false ]) )->to_array();
        $outcome  = [ 'integration' => 'woocommerce', 'error' => [ 'code' => 'opt_in_required', 'message' => 'Off by default.', 'data' => [ 'unavailable' => $contract ] ] ];

        $envelope = self::meta((array) Refusal_Result::for_outcome($outcome));

        $this->assertSame('opt_in_required', $envelope['reason']);
        $this->assertFalse($envelope['hidden']);
        $this->assertSame([ 'integration' => 'woocommerce' ], $envelope['data']);
    }

    public function test_results_are_not_refusals(): void
    {
        $results = [
            [ 'integration' => 'theme', 'operation' => 'list-operations', 'result' => [ 'error' => 'a value named error' ] ],
            [ 'integration' => 'theme', 'error' => [ 'code' => 'x', 'message' => 'y' ], 'result' => [] ],
            [ 'error' => [ 'code' => 'x', 'message' => 'y' ] ],
            [ 'integration' => 'theme', 'error' => 'not an envelope' ],
            [ 'integration' => 'theme', 'error' => [ 'code' => 7, 'message' => 'y' ] ],
            [ 'id' => 1 ],
            [],
            'text',
            null,
            42,
        ];
        foreach ($results as $outcome) {
            $this->assertNull(Refusal::of($outcome), (string) wp_json_encode($outcome));
            $this->assertNull(Refusal_Result::for_outcome($outcome));
        }
    }

    public function test_data_no_encoder_can_write_is_dropped_rather_than_the_refusal(): void
    {
        $result = Refusal_Result::of(new Refusal('wpmcp_odd', 'Odd value.', [ 'ratio' => INF ]));

        $this->assertTrue($result['isError']);
        $this->assertSame('{"code":"wpmcp_odd","message":"Odd value.","data":{}}', $result['content'][1]['text']);
        $this->assertEquals(new \stdClass(), self::meta($result)['data']);
        $this->assertNotFalse(wp_json_encode($result));
    }

    public function test_scalar_error_data_is_kept_under_value(): void
    {
        $envelope = self::meta(Refusal_Result::of(Refusal::from_error(new \WP_Error('wpmcp_x', 'X.', 'detail'))));

        $this->assertSame([ 'value' => 'detail' ], $envelope['data']);
    }
}
