<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #676 against the real Kadence Blocks (bin/test-local.sh
 * --live-blocks, WPMCP_LIVE_BLOCKS=1): its own kadence_form post type and
 * registered _kad_form_* meta, with their defaults. The reads and the
 * notification change run on that form and are undone. Skipped elsewhere;
 * the live leg runs with --fail-on-skipped.
 *
 * @group blocks-live
 */
class KadenceFormsLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('KADENCE_BLOCKS_VERSION') || ! post_type_exists('kadence_form')) {
            $this->markTestSkipped('Needs the real Kadence Blocks (bin/test-local.sh --live-blocks, WPMCP_LIVE_BLOCKS=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_enable_kadence_forms_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_kadence_forms_write');
        parent::tearDown();
    }

    public function test_reads_and_the_notification_change_run_on_kadences_own_meta_and_are_undone(): void
    {
        $form = self::factory()->post->create([ 'post_type' => 'kadence_form', 'post_title' => 'Live Contact', 'post_status' => 'publish' ]);
        update_post_meta($form, '_kad_form_fields', [ [ 'uniqueID' => 'x1', 'name' => 'field1', 'label' => 'Email', 'type' => 'email', 'required' => true ] ]);
        $this->assertSame('email_field', get_post_meta($form, '_kad_form_email', true)['replyTo'] ?? null, 'Kadence registers the email meta with its defaults');

        $pair   = new Plugin_Data_Integration();
        $fields = $pair->handle_read([ 'operation' => 'kadenceforms-list-fields', 'args' => [ 'form_id' => $form ] ]);
        $this->assertArrayNotHasKey('error', $fields, (string) wp_json_encode($fields));
        $this->assertSame([ 'field1' ], array_column($fields['result']['fields'], 'name'));

        $out = $pair->handle_write([ 'operation' => 'kadenceforms-update-notification', 'args' => [ 'form_id' => $form, 'notification' => 'email', 'active' => true, 'email_to' => 'live@example.test' ] ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('live@example.test', get_post_meta($form, '_kad_form_email', true)['emailTo']);
        $this->assertContains('email', get_post_meta($form, '_kad_form_actions', true));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('', get_post_meta($form, '_kad_form_email', true)['emailTo'] ?? '', 'back to Kadence\'s default');
    }
}
