<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\JetFormBuilder_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Session;

require_once __DIR__ . '/../../support/jetformbuilder-stubs.php';

/**
 * JetFormBuilder (issue #676) on the plugin-data pair, against its real
 * table layout (tests/support/jetformbuilder-stubs.php). A form is a
 * jet-form-builder post whose content holds its jet-forms/* field blocks;
 * a submission is a jet_fb_records row with one jet_fb_records_fields row
 * per value.
 */
class JetFormBuilderPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'jetformbuilder-list-forms'          => 'read',
        'jetformbuilder-get-form'            => 'read',
        'jetformbuilder-list-fields'         => 'read',
        'jetformbuilder-list-entries'        => 'read',
        'jetformbuilder-get-entry'           => 'read',
        'jetformbuilder-update-entry-status' => 'write',
        'jetformbuilder-delete-entry'        => 'destructive',
    ];

    private int $form;

    private int $entry;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        wpmcp_test_create_jetformbuilder_tables();
    }

    public static function tear_down_after_class(): void
    {
        wpmcp_test_drop_jetformbuilder_tables();
        parent::tear_down_after_class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_post_type('jet-form-builder', [ 'public' => false ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_jetformbuilder_active', '__return_true');
        add_filter('wpmcp_enable_jetformbuilder_write', '__return_true');

        $this->form  = self::factory()->post->create([
            'post_type'    => 'jet-form-builder',
            'post_title'   => 'Contact',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:jet-forms/text-field {"name":"your_name","label":"Name","required":true} /-->'
                . '<!-- wp:jet-forms/text-field {"name":"email","label":"Email","field_type":"email"} /-->'
                . '<!-- wp:jet-forms/select-field {"name":"plan","label":"Plan","field_options":[{"label":"Free","value":"free"},{"label":"Paid","value":"paid"}]} /-->'
                . '<!-- wp:jet-forms/submit-field {"label":"Send"} /-->',
        ]);
        $this->entry = $this->record($this->form, [ 'your_name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'plan' => 'paid' ]);
        $this->record($this->form, [ 'your_name' => 'Bob' ], 1);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_jetformbuilder_active');
        remove_all_filters('wpmcp_enable_jetformbuilder_write');
        unregister_post_type('jet-form-builder');
        parent::tearDown();
    }

    /** A record as JetFormBuilder's Save_Record action writes it. */
    private function record(int $form, array $values, int $viewed = 0): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'jet_fb_records', [
            'form_id' => $form, 'user_id' => 0, 'from_content_id' => 1, 'from_content_type' => 'post',
            'status' => 'success', 'ip_address' => '203.0.113.5', 'user_agent' => 'UA', 'referrer' => 'https://example.test/',
            'submit_type' => 'reload', 'is_viewed' => $viewed,
        ]);
        $id = (int) $wpdb->insert_id;
        foreach ($values as $name => $value) {
            $wpdb->insert($wpdb->prefix . 'jet_fb_records_fields', [ 'record_id' => $id, 'field_name' => $name, 'field_value' => $value, 'field_type' => 'text-field', 'field_attrs' => '{}' ]);
        }
        $wpdb->insert($wpdb->prefix . 'jet_fb_records_actions', [ 'record_id' => $id, 'action_slug' => 'save_record', 'action_id' => 1, 'status' => 'success' ]);
        return $id;
    }

    private function rows(string $table, int $record): int
    {
        global $wpdb;
        $column = 'records' === $table ? 'id' : 'record_id';
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i = %d', $wpdb->prefix . 'jet_fb_' . $table, $column, $record));
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ] + ($confirm ? [ 'confirm' => true ] : []));
    }

    public function test_the_catalog_lists_every_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
        }
    }

    public function test_without_jetformbuilder_a_call_answers_plugin_missing(): void
    {
        remove_all_filters('wpmcp_jetformbuilder_active');
        add_filter('wpmcp_jetformbuilder_active', '__return_false');
        $out = $this->read('jetformbuilder-list-forms');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate JetFormBuilder', $out['error']['message']);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_jetformbuilder_active');
        $this->assertSame(defined('JET_FORM_BUILDER_VERSION'), JetFormBuilder_Pack::active());
    }

    public function test_forms_and_fields_are_read_from_the_field_blocks(): void
    {
        $forms = $this->read('jetformbuilder-list-forms');
        $this->assertSame([ 'Contact' ], array_column($forms['forms'], 'title'));
        $this->assertSame(2, $forms['forms'][0]['entries']);
        $this->assertSame(1, $forms['forms'][0]['unread']);
        $this->assertSame(sprintf('[jet_fb_form form_id="%d"]', $this->form), $forms['forms'][0]['shortcode']);

        $fields = $this->read('jetformbuilder-list-fields', [ 'form_id' => $this->form ])['fields'];
        $this->assertSame([ 'your_name', 'email', 'plan' ], array_column($fields, 'name'), 'the submit button is not a field');
        $this->assertTrue($fields[0]['required']);
        $this->assertSame('select-field', $fields[2]['type']);
        $this->assertSame([ 'free', 'paid' ], array_column($fields[2]['options'], 'value'));

        $form = $this->read('jetformbuilder-get-form', [ 'form_id' => $this->form ])['form'];
        $this->assertCount(3, $form['fields']);

        $this->assertSame('form_not_found', $this->read('jetformbuilder-get-form', [ 'form_id' => $this->entry + 9999 ])['error']['code'] ?? null);
    }

    public function test_list_entries_masks_values_and_get_entry_returns_one_in_full(): void
    {
        $list = $this->read('jetformbuilder-list-entries', [ 'form_id' => $this->form ]);
        $this->assertSame(2, $list['total']);
        $this->assertTrue($list['masked']);
        $json = (string) wp_json_encode($list);
        foreach ([ 'ada@example.test', 'Ada Lovelace', '203.0.113.5' ] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }

        $entry = $this->read('jetformbuilder-get-entry', [ 'entry_id' => $this->entry ])['entry'];
        $this->assertSame($this->form, $entry['form_id']);
        $this->assertFalse($entry['viewed']);
        $this->assertSame('ada@example.test', array_column($entry['fields'], 'value', 'name')['email']);
        $this->assertSame('Email', array_column($entry['fields'], 'label', 'name')['email']);
        $this->assertSame('203.0.113.5', $entry['ip_address']);
        $this->assertSame(0, $this->read('jetformbuilder-get-entry', [ 'entry_id' => $this->entry ])['entry']['viewed'] ? 1 : 0, 'reading does not mark it read');

        $this->assertSame('entry_not_found', $this->read('jetformbuilder-get-entry', [ 'entry_id' => 99999 ])['error']['code'] ?? null);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_jetformbuilder_write');
        foreach ([ [ 'jetformbuilder-update-entry-status', [ 'entry_id' => $this->entry, 'viewed' => true ] ], [ 'jetformbuilder-delete-entry', [ 'entry_id' => $this->entry ] ] ] as [ $op, $args ]) {
            $out = $this->write($op, $args, true);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_jetformbuilder_write', $out['error']['message']);
        }
    }

    public function test_marking_an_entry_read_rolls_back(): void
    {
        $out = $this->write('jetformbuilder-update-entry-status', [ 'entry_id' => $this->entry, 'viewed' => true ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertTrue($out['result']['entry']['viewed']);
        $this->assertFalse($out['result']['previous']['viewed']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertFalse($this->read('jetformbuilder-get-entry', [ 'entry_id' => $this->entry ])['entry']['viewed']);
    }

    public function test_delete_entry_needs_confirm_then_removes_every_row_and_one_session_restores_them(): void
    {
        $preview = $this->write('jetformbuilder-delete-entry', [ 'entry_id' => $this->entry ]);
        $this->assertSame('confirmation_required', $preview['error']['code'] ?? null);
        $this->assertSame(3, $preview['error']['data']['values']);
        $this->assertStringNotContainsString('ada@example.test', (string) wp_json_encode($preview));

        $out = $this->write('jetformbuilder-delete-entry', [ 'entry_id' => $this->entry ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        foreach ([ 'records', 'records_fields', 'records_actions' ] as $table) {
            $this->assertSame(0, $this->rows($table, $this->entry), $table);
        }
        $this->assertSame(1, $this->read('jetformbuilder-list-entries', [ 'form_id' => $this->form ])['total'], 'the other entry stays');

        ( new Rollback_Session() )->handle([ 'session_id' => $out['session_id'] ]);
        $this->assertSame(1, $this->rows('records', $this->entry));
        $this->assertSame(3, $this->rows('records_fields', $this->entry));
        $this->assertSame(1, $this->rows('records_actions', $this->entry));
    }

    public function test_delete_entry_refuses_an_entry_with_a_payment(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'jet_fb_payments_to_records', [ 'payment_id' => 7, 'record_id' => $this->entry ]);
        $out = $this->write('jetformbuilder-delete-entry', [ 'entry_id' => $this->entry ], true);
        $this->assertSame('entry_has_payments', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame(1, $this->rows('records', $this->entry));
    }
}
