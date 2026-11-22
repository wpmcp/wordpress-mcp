<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Kadence_Forms_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Kadence Blocks advanced forms (issue #676) on the plugin-data pair. A form
 * is a kadence_form post (Kadence Blocks 3.9) with its settings in
 * _kad_form_* post meta, as includes/advanced-form/advanced-form-cpt.php
 * registers them: _kad_form_fields (uniqueID, name, label, type, required,
 * options), _kad_form_actions (email, autoEmail, redirect, ...),
 * _kad_form_email and _kad_form_autoEmail (emailTo, subject, fromEmail,
 * fromName, replyTo, cc, bcc, html; message on the auto email) and
 * _kad_form_messages.
 */
class KadenceFormsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'kadenceforms-list-forms'          => 'read',
        'kadenceforms-get-form'            => 'read',
        'kadenceforms-list-fields'         => 'read',
        'kadenceforms-list-notifications'  => 'read',
        'kadenceforms-update-notification' => 'write',
    ];

    private int $form;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_post_type('kadence_form', [ 'public' => false ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_kadence_forms_active', '__return_true');
        add_filter('wpmcp_enable_kadence_forms_write', '__return_true');
        $this->form = self::factory()->post->create([ 'post_type' => 'kadence_form', 'post_title' => 'Contact', 'post_status' => 'publish' ]);
        update_post_meta($this->form, '_kad_form_fields', [
            [ 'uniqueID' => 'a1', 'name' => 'field1', 'label' => 'Name', 'type' => 'text', 'required' => true ],
            [ 'uniqueID' => 'a2', 'name' => 'field2', 'label' => 'Email', 'type' => 'email', 'required' => true ],
            [ 'uniqueID' => 'a3', 'name' => 'field3', 'label' => 'Topic', 'type' => 'select', 'options' => [ [ 'label' => 'Sales', 'value' => 'sales' ] ] ],
        ]);
        update_post_meta($this->form, '_kad_form_actions', [ 'email', 'redirect' ]);
        update_post_meta($this->form, '_kad_form_email', [ 'emailTo' => 'owner@example.test', 'subject' => 'New message', 'fromEmail' => '', 'fromName' => '', 'replyTo' => 'email_field', 'cc' => '', 'bcc' => '', 'html' => true ]);
        update_post_meta($this->form, '_kad_form_messages', [ 'success' => 'Thanks!', 'error' => '' ]);
        update_post_meta($this->form, '_kad_form_redirect', '/thank-you/');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_kadence_forms_active');
        remove_all_filters('wpmcp_enable_kadence_forms_write');
        unregister_post_type('kadence_form');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    public function test_the_catalog_lists_every_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
        }
    }

    public function test_without_kadence_blocks_a_call_answers_plugin_missing(): void
    {
        remove_all_filters('wpmcp_kadence_forms_active');
        add_filter('wpmcp_kadence_forms_active', '__return_false');
        $out = $this->read('kadenceforms-list-forms');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Kadence Blocks', $out['error']['message']);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_kadence_forms_active');
        $this->assertSame(defined('KADENCE_BLOCKS_VERSION'), Kadence_Forms_Pack::active());
    }

    public function test_forms_fields_and_settings_are_read_from_the_form_meta(): void
    {
        $forms = $this->read('kadenceforms-list-forms')['forms'];
        $this->assertSame([ 'Contact' ], array_column($forms, 'title'));
        $this->assertSame([ 'email', 'redirect' ], $forms[0]['actions']);

        $fields = $this->read('kadenceforms-list-fields', [ 'form_id' => $this->form ])['fields'];
        $this->assertSame([ 'field1', 'field2', 'field3' ], array_column($fields, 'name'));
        $this->assertSame('Email', $fields[1]['label']);
        $this->assertTrue($fields[1]['required']);
        $this->assertSame([ 'sales' ], array_column($fields[2]['options'], 'value'));

        $form = $this->read('kadenceforms-get-form', [ 'form_id' => $this->form ])['form'];
        $this->assertSame('Thanks!', $form['messages']['success']);
        $this->assertSame('/thank-you/', $form['redirect']);
        $this->assertSame('form_not_found', $this->read('kadenceforms-get-form', [ 'form_id' => 999999 ])['error']['code'] ?? null);
    }

    public function test_notifications_report_the_admin_and_auto_emails_and_whether_each_is_on(): void
    {
        $list = array_column($this->read('kadenceforms-list-notifications', [ 'form_id' => $this->form ])['notifications'], null, 'id');
        $this->assertTrue($list['email']['active']);
        $this->assertSame('owner@example.test', $list['email']['email_to']);
        $this->assertSame('New message', $list['email']['subject']);
        $this->assertFalse($list['autoEmail']['active'], 'the auto email is off until its action is added');
    }

    public function test_update_notification_is_opt_in_and_rolls_back(): void
    {
        remove_all_filters('wpmcp_enable_kadence_forms_write');
        $args = [ 'form_id' => $this->form, 'notification' => 'email', 'email_to' => 'sales@example.test', 'subject' => 'Lead' ];
        $this->assertSame('opt_in_required', $this->write('kadenceforms-update-notification', $args)['error']['code'] ?? null);

        add_filter('wpmcp_enable_kadence_forms_write', '__return_true');
        $out = $this->write('kadenceforms-update-notification', $args);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $email = get_post_meta($this->form, '_kad_form_email', true);
        $this->assertSame('sales@example.test', $email['emailTo']);
        $this->assertSame('Lead', $email['subject']);
        $this->assertSame('email_field', $email['replyTo'], 'untouched keys stay');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('owner@example.test', get_post_meta($this->form, '_kad_form_email', true)['emailTo']);
    }

    public function test_update_notification_turns_the_auto_email_on_and_rolls_back(): void
    {
        $out = $this->write('kadenceforms-update-notification', [ 'form_id' => $this->form, 'notification' => 'autoEmail', 'active' => true, 'email_to' => '{field2}', 'message' => 'We got it' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertContains('autoEmail', get_post_meta($this->form, '_kad_form_actions', true));
        $this->assertSame('We got it', get_post_meta($this->form, '_kad_form_autoEmail', true)['message']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ 'email', 'redirect' ], get_post_meta($this->form, '_kad_form_actions', true));
        $this->assertSame('', get_post_meta($this->form, '_kad_form_autoEmail', true), 'meta the write created is removed again');
    }

    public function test_update_notification_refuses_a_bad_recipient(): void
    {
        $out = $this->write('kadenceforms-update-notification', [ 'form_id' => $this->form, 'notification' => 'email', 'email_to' => 'not an email' ]);
        $this->assertSame('invalid_recipient', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame('owner@example.test', get_post_meta($this->form, '_kad_form_email', true)['emailTo']);
    }
}
