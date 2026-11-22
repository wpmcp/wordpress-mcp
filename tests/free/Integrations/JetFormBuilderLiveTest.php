<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Session;

/**
 * Issue #676 against the real JetFormBuilder (bin/test-local.sh --live-forms,
 * WPMCP_LIVE_FORMS=1): its own models create the record tables, with their
 * foreign keys, and write the record, its values and its action result. The
 * reads, the read-state change and the delete run on those rows and are
 * undone. Skipped elsewhere; the live leg runs with --fail-on-skipped.
 *
 * @group forms-live
 */
class JetFormBuilderLiveTest extends \WP_UnitTestCase
{
    private const MODELS = [
        'JFB_Modules\\Form_Record\\Models\\Record_Model',
        'JFB_Modules\\Form_Record\\Models\\Record_Field_Model',
        'JFB_Modules\\Form_Record\\Models\\Record_Action_Result_Model',
        'JFB_Modules\\Form_Record\\Models\\Record_Error_Model',
    ];

    private int $form;

    private int $entry;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        if (defined('JET_FORM_BUILDER_VERSION')) {
            foreach (self::MODELS as $model) {
                ( new $model() )->create(); // DDL, outside any test transaction.
            }
        }
    }

    public static function tear_down_after_class(): void
    {
        global $wpdb;
        if (defined('JET_FORM_BUILDER_VERSION')) {
            foreach ([ 'records_fields', 'records_actions', 'records_errors', 'records' ] as $table) {
                $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'jet_fb_' . $table));
            }
        }
        parent::tear_down_after_class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('JET_FORM_BUILDER_VERSION') || ! class_exists(self::MODELS[0])) {
            $this->markTestSkipped('Needs the real JetFormBuilder (bin/test-local.sh --live-forms, WPMCP_LIVE_FORMS=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_enable_jetformbuilder_write', '__return_true');

        $this->form  = self::factory()->post->create([
            'post_type'    => 'jet-form-builder',
            'post_title'   => 'Live Contact',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:jet-forms/text-field {"name":"email","label":"Email","required":true} /--><!-- wp:jet-forms/submit-field {"label":"Send"} /-->',
        ]);
        $records     = new \JFB_Modules\Form_Record\Models\Record_Model();
        $this->entry = $records->insert([
            'form_id' => $this->form, 'user_id' => 0, 'from_content_id' => 1, 'from_content_type' => 'post',
            'status' => 'success', 'ip_address' => '203.0.113.5', 'user_agent' => 'UA', 'referrer' => '', 'submit_type' => 'reload', 'is_viewed' => 0,
        ]);
        ( new \JFB_Modules\Form_Record\Models\Record_Field_Model() )->insert([ 'record_id' => $this->entry, 'field_name' => 'email', 'field_value' => 'ada@example.test', 'field_type' => 'text-field', 'field_attrs' => '{}' ]);
        ( new \JFB_Modules\Form_Record\Models\Record_Action_Result_Model() )->insert([ 'record_id' => $this->entry, 'action_slug' => 'save_record', 'action_id' => 1, 'status' => 'success' ]);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_jetformbuilder_write');
        parent::tearDown();
    }

    private function call(string $half, string $op, array $args, bool $confirm = false): array
    {
        $out = (new Plugin_Data_Integration())->{$half}([ 'operation' => $op, 'args' => $args ] + ($confirm ? [ 'confirm' => true ] : []));
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function rows(string $table): int
    {
        global $wpdb;
        $column = 'records' === $table ? 'id' : 'record_id';
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i = %d', $wpdb->prefix . 'jet_fb_' . $table, $column, $this->entry));
    }

    public function test_reads_mark_read_and_delete_run_on_jetformbuilders_own_rows_and_are_undone(): void
    {
        $fields = $this->call('handle_read', 'jetformbuilder-list-fields', [ 'form_id' => $this->form ])['result']['fields'];
        $this->assertSame([ 'email' ], array_column($fields, 'name'));

        $entry = $this->call('handle_read', 'jetformbuilder-get-entry', [ 'entry_id' => $this->entry ])['result']['entry'];
        $this->assertSame('ada@example.test', $entry['fields'][0]['value']);
        $this->assertSame('Email', $entry['fields'][0]['label']);

        $viewed = $this->call('handle_write', 'jetformbuilder-update-entry-status', [ 'entry_id' => $this->entry, 'viewed' => true ]);
        $this->assertTrue($viewed['result']['entry']['viewed']);
        $this->assertTrue(Rollback_Service::restore_operation($viewed['operation_id']));
        $this->assertFalse($this->call('handle_read', 'jetformbuilder-get-entry', [ 'entry_id' => $this->entry ])['result']['entry']['viewed']);

        $deleted = $this->call('handle_write', 'jetformbuilder-delete-entry', [ 'entry_id' => $this->entry ], true);
        $this->assertSame(0, $this->rows('records'));
        $this->assertSame(0, $this->rows('records_fields'));
        $this->assertSame(0, $this->rows('records_actions'));

        ( new Rollback_Session() )->handle([ 'session_id' => $deleted['session_id'] ]);
        $this->assertSame(1, $this->rows('records'), 'the record comes back before its children, which its foreign keys need');
        $this->assertSame(1, $this->rows('records_fields'));
        $this->assertSame(1, $this->rows('records_actions'));
    }
}
