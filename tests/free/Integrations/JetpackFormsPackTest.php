<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Jetpack_Forms_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Jetpack Forms (issue #676) on the plugin-data pair. A response is a
 * `feedback` post as Jetpack 16's Feedback::save() writes it: post_status
 * publish (inbox), spam or trash; comment_status open (unread) or closed
 * (read); post_parent the form or the page the form is on; post_mime_type
 * v3 and post_content the JSON of subject, ip, user_agent, the source and
 * fields (key, label, value, type, meta, form_field_id).
 */
class JetpackFormsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'jetpackforms-list-forms'          => 'read',
        'jetpackforms-list-entries'        => 'read',
        'jetpackforms-get-entry'           => 'read',
        'jetpackforms-update-entry-status' => 'write',
        'jetpackforms-delete-entry'        => 'destructive',
    ];

    private int $page;

    private int $entry;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_post_type('feedback', [ 'public' => false ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_jetpack_forms_active', '__return_true');
        add_filter('wpmcp_enable_jetpack_forms_write', '__return_true');
        $this->page  = self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'Contact us', 'post_status' => 'publish' ]);
        $this->entry = $this->feedback([ 'Name' => 'Ada Lovelace', 'Email' => 'ada@example.test', 'Message' => 'Hello' ]);
        $this->feedback([ 'Name' => 'Bob' ], 'spam', 'closed');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_jetpack_forms_active');
        remove_all_filters('wpmcp_enable_jetpack_forms_write');
        unregister_post_type('feedback');
        parent::tearDown();
    }

    /** A response as Feedback::save() stores it. */
    private function feedback(array $values, string $status = 'publish', string $read = 'open'): int
    {
        $fields = [];
        $i      = 1;
        foreach ($values as $label => $value) {
            $fields[] = [ 'key' => $i . '_' . $label, 'label' => $label, 'value' => $value, 'type' => 'email' === strtolower($label) ? 'email' : 'text', 'meta' => [], 'form_field_id' => 'f' . $i ];
            ++$i;
        }
        $content = wp_json_encode([
            'subject' => 'Contact us', 'ip' => '203.0.113.5', 'country_code' => 'GB', 'user_agent' => 'UA', 'form_fill_duration' => 12,
            'notification_recipients' => [ 'owner@example.test' ], 'logged_in_user' => 0,
            'entry_title' => 'Contact us', 'entry_page' => 1, 'source_id' => $this->page, 'source_type' => 'single', 'request_url' => 'https://example.test/contact/',
            'fields' => $fields,
        ], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);

        return self::factory()->post->create([
            'post_type'      => 'feedback',
            'post_status'    => $status,
            'post_title'     => 'Ada - 2026-11-01',
            'post_content'   => wp_slash((string) $content),
            'post_mime_type' => 'v3',
            'post_parent'    => $this->page,
            'comment_status' => $read,
        ]);
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

    public function test_without_jetpack_forms_a_call_answers_plugin_missing(): void
    {
        remove_all_filters('wpmcp_jetpack_forms_active');
        add_filter('wpmcp_jetpack_forms_active', '__return_false');
        $out = $this->read('jetpackforms-list-forms');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Jetpack', $out['error']['message']);
    }

    public function test_presence_defaults_to_the_forms_package(): void
    {
        remove_all_filters('wpmcp_jetpack_forms_active');
        $this->assertSame(class_exists('Automattic\\Jetpack\\Forms\\ContactForm\\Feedback'), Jetpack_Forms_Pack::active());
    }

    public function test_forms_are_the_sources_responses_came_from_with_counts(): void
    {
        $forms = $this->read('jetpackforms-list-forms')['forms'];
        $this->assertCount(1, $forms);
        $this->assertSame($this->page, $forms[0]['id']);
        $this->assertSame('Contact us', $forms[0]['title']);
        $this->assertSame([ 'inbox' => 1, 'spam' => 1, 'trash' => 0, 'unread' => 1 ], $forms[0]['counts']);
    }

    public function test_list_entries_masks_values_and_get_entry_returns_one_in_full(): void
    {
        $list = $this->read('jetpackforms-list-entries', [ 'form_id' => $this->page ]);
        $this->assertSame(1, $list['total'], 'the inbox by default');
        $this->assertTrue($list['masked']);
        foreach ([ 'ada@example.test', 'Ada Lovelace', '203.0.113.5' ] as $leak) {
            $this->assertStringNotContainsString($leak, (string) wp_json_encode($list));
        }
        $this->assertSame(1, $this->read('jetpackforms-list-entries', [ 'status' => 'spam' ])['total']);

        $entry = $this->read('jetpackforms-get-entry', [ 'entry_id' => $this->entry ])['entry'];
        $this->assertSame('inbox', $entry['status']);
        $this->assertFalse($entry['read']);
        $this->assertSame('ada@example.test', array_column($entry['fields'], 'value', 'label')['Email']);
        $this->assertSame('203.0.113.5', $entry['ip_address']);
        $this->assertSame($this->page, $entry['form_id']);

        $this->assertSame('entry_not_found', $this->read('jetpackforms-get-entry', [ 'entry_id' => $this->page ])['error']['code'] ?? null);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_jetpack_forms_write');
        foreach ([ [ 'jetpackforms-update-entry-status', [ 'entry_id' => $this->entry, 'read' => true ] ], [ 'jetpackforms-delete-entry', [ 'entry_id' => $this->entry ] ] ] as [ $op, $args ]) {
            $out = $this->write($op, $args, true);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
        }
    }

    public function test_marking_spam_and_read_rolls_back(): void
    {
        $out = $this->write('jetpackforms-update-entry-status', [ 'entry_id' => $this->entry, 'status' => 'spam', 'read' => true ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('spam', get_post_status($this->entry));
        $this->assertSame('closed', get_post($this->entry)->comment_status);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        clean_post_cache($this->entry);
        $this->assertSame('publish', get_post_status($this->entry));
        $this->assertSame('open', get_post($this->entry)->comment_status);
    }

    public function test_delete_entry_needs_confirm_and_rollback_brings_it_back_at_its_id(): void
    {
        $preview = $this->write('jetpackforms-delete-entry', [ 'entry_id' => $this->entry ]);
        $this->assertSame('confirmation_required', $preview['error']['code'] ?? null);
        $this->assertSame(3, $preview['error']['data']['values']);
        $this->assertStringNotContainsString('ada@example.test', (string) wp_json_encode($preview));

        $out = $this->write('jetpackforms-delete-entry', [ 'entry_id' => $this->entry ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertNull(get_post($this->entry));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        clean_post_cache($this->entry);
        $this->assertSame('feedback', get_post_type($this->entry));
        $this->assertSame('ada@example.test', array_column($this->read('jetpackforms-get-entry', [ 'entry_id' => $this->entry ])['entry']['fields'], 'value', 'label')['Email']);
    }
}
