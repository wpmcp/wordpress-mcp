<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Modules\Cmb2\Ops\Cmb2_Ops;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Support\Cmb2_Box_Double;
use WPMCP\Tools\Rollback_Operation;

require_once __DIR__ . '/../../support/cmb2-double.php';

/**
 * Issue #1089: CMB2 boxes and fields on the theme pair. Boxes registered in
 * code are listed (the ones added on cmb2_admin_init too, which a REST or
 * MCP request never fires), values read per post or per options page with
 * secrets hidden, and one allowlisted field written through the field's own
 * save_field(), refused for unknown ids and unsupported types before any
 * snapshot, and undone by rollback-operation.
 */
final class Cmb2PackTest extends \WP_UnitTestCase
{
    /** @var array<string, Cmb2_Box_Double> */
    private array $boxes = [];

    /** @var array<string, Cmb2_Box_Double> boxes a cmb2_admin_init callback adds */
    private array $admin_boxes = [];

    private int $post_id = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        unset($GLOBALS['wp_actions']['cmb2_admin_init']);
        add_filter('wpmcp_cmb2_active', '__return_true');
        add_filter('wpmcp_cmb2_boxes', [ $this, 'boxes' ]);
        add_action('cmb2_admin_init', [ $this, 'register_admin_boxes' ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->post_id = self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'About' ]);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_cmb2_active');
        remove_all_filters('wpmcp_cmb2_boxes');
        remove_all_filters(Cmb2_Ops::ENABLE_FILTER);
        remove_all_filters('cmb2_override_hero_title_meta_save');
        remove_all_actions('cmb2_admin_init');
        unset($GLOBALS['wp_actions']['cmb2_admin_init']);
        delete_option('site_settings');
        $this->boxes       = [];
        $this->admin_boxes = [];
        \WPMCP\Plugin::instance()->kernel()->use_registry_for_tests(null);
        parent::tearDown();
    }

    /** @return array<string, Cmb2_Box_Double> */
    public function boxes(): array
    {
        return $this->boxes;
    }

    public function register_admin_boxes(): void
    {
        foreach ($this->admin_boxes as $id => $box) {
            $this->boxes[ $id ] = $box;
        }
    }

    /** A page box shaped like a theme's hero section: text, email, select, checkbox, colour, a group and two secrets. */
    private function page_box(array $extra_fields = []): Cmb2_Box_Double
    {
        $box = new Cmb2_Box_Double('hero_box', [ 'title' => 'Hero', 'object_types' => [ 'page' ] ], array_merge([
            [ 'id' => 'hero_title', 'type' => 'text', 'name' => 'Hero title', 'default' => 'Welcome' ],
            [ 'id' => 'hero_intro', 'type' => 'textarea', 'name' => 'Intro' ],
            [ 'id' => 'hero_email', 'type' => 'text_email', 'name' => 'Contact email' ],
            [ 'id' => 'hero_layout', 'type' => 'select', 'name' => 'Layout', 'options' => [ 'left' => 'Left', 'center' => 'Center' ], 'default' => 'left' ],
            [ 'id' => 'hero_dark', 'type' => 'checkbox', 'name' => 'Dark' ],
            [ 'id' => 'hero_color', 'type' => 'colorpicker', 'name' => 'Accent' ],
            [ 'id' => 'hero_tags', 'type' => 'multicheck', 'name' => 'Badges', 'options' => [ 'new' => 'New', 'hot' => 'Hot', 'sale' => 'Sale' ] ],
            [ 'id' => 'hero_pages', 'type' => 'select', 'name' => 'Linked page', 'options_cb' => 'get_pages' ],
            [ 'id' => 'hero_slides', 'type' => 'group', 'name' => 'Slides', 'fields' => [
                [ 'id' => 'caption', 'type' => 'text', 'name' => 'Caption' ],
                [ 'id' => 'api_token', 'type' => 'text', 'name' => 'Token' ],
            ] ],
            [ 'id' => 'maps_api_key', 'type' => 'text', 'name' => 'Maps API key' ],
            [ 'id' => 'smtp_login', 'type' => 'text', 'name' => 'SMTP', 'attributes' => [ 'type' => 'password' ] ],
        ], $extra_fields));
        $this->boxes['hero_box'] = $box;
        return $box;
    }

    private function options_box(array $config = []): Cmb2_Box_Double
    {
        $box = new Cmb2_Box_Double('site_options', $config + [ 'title' => 'Site settings', 'object_types' => [ 'options-page' ], 'option_key' => 'site_settings' ], [
            [ 'id' => 'footer_text', 'type' => 'textarea_small', 'name' => 'Footer text', 'default' => 'Made with care' ],
            [ 'id' => 'phone', 'type' => 'text', 'name' => 'Phone' ],
            [ 'id' => 'license_key', 'type' => 'text', 'name' => 'Licence' ],
        ]);
        $this->boxes['site_options'] = $box;
        return $box;
    }

    /**
     * The theme pair, with the module operations read again. The kernel keeps
     * a module's operation definitions and services for the request (here one
     * PHP process for the whole suite): whether the write is opted in is part
     * of the definitions, and the box list is read once per request, so each
     * call in a test reads them as a new request would.
     */
    private function pair(): Theme_Integration
    {
        \WPMCP\Plugin::instance()->kernel()->use_registry_for_tests(null);
        return new Theme_Integration();
    }

    private function read(string $op, array $args = []): array
    {
        return $this->pair()->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(array $args): array
    {
        return $this->pair()->handle_write([ 'operation' => 'set-cmb2-value', 'args' => $args ]);
    }

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    private function opt_in(): void
    {
        add_filter(Cmb2_Ops::ENABLE_FILTER, '__return_true');
    }

    private function snapshots(): int
    {
        return count(Snapshot_Store::recent(100));
    }

    /** @return array<string, array<string, mixed>> */
    private function values(array $args): array
    {
        $out = $this->read('get-cmb2-values', $args);
        $this->assertArrayHasKey('result', $out, (string) wp_json_encode($out));
        return array_column($out['result']['fields'], null, 'id');
    }

    public function test_ops_are_on_the_theme_pair_and_the_write_is_off_until_opt_in(): void
    {
        $this->page_box();
        update_post_meta($this->post_id, 'hero_title', 'Hello');
        $ops = array_column($this->pair()->catalog()['operations'], null, 'name');

        $this->assertSame('read', $ops['list-cmb2-boxes']['mode']);
        $this->assertSame('read', $ops['get-cmb2-values']['mode']);
        $this->assertSame('write', $ops['set-cmb2-value']['mode']);
        $this->assertFalse($ops['set-cmb2-value']['enabled']);

        $out = $this->write([ 'box' => 'hero_box', 'post_id' => $this->post_id, 'field' => 'hero_title', 'value' => 'Changed' ]);
        $this->assertSame('opt_in_required', $this->code($out));
        $this->assertStringContainsString(Cmb2_Ops::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame('Hello', get_post_meta($this->post_id, 'hero_title', true));
    }

    public function test_without_cmb2_the_ops_name_cmb2(): void
    {
        remove_all_filters('wpmcp_cmb2_active');
        add_filter('wpmcp_cmb2_active', '__return_false');

        $out = $this->read('list-cmb2-boxes');

        $this->assertSame('cmb2_inactive', $this->code($out));
        $this->assertStringContainsString('CMB2', $out['error']['message']);
    }

    public function test_list_shows_boxes_registered_on_cmb2_admin_init(): void
    {
        $this->page_box();
        $this->admin_boxes['admin_only'] = new Cmb2_Box_Double('admin_only', [ 'title' => 'Admin only', 'object_types' => [ 'post' ] ], [
            [ 'id' => 'note', 'type' => 'text', 'name' => 'Note' ],
        ]);
        $this->boxes['profile'] = new Cmb2_Box_Double('profile', [ 'title' => 'Profile', 'object_types' => [ 'user' ] ], [
            [ 'id' => 'twitter', 'type' => 'text', 'name' => 'Twitter' ],
        ]);
        $this->options_box();

        $out = $this->read('list-cmb2-boxes');
        $this->assertArrayHasKey('result', $out, (string) wp_json_encode($out));
        $boxes = array_column($out['result']['boxes'], null, 'id');

        $this->assertSame([ 'hero_box', 'profile', 'site_options', 'admin_only' ], array_keys($boxes));
        $this->assertTrue($out['result']['admin_boxes_loaded']);
        $this->assertSame('cmb2_admin_init', $boxes['admin_only']['registered_on']);
        $this->assertSame('cmb2_init', $boxes['hero_box']['registered_on']);
        $this->assertSame('post', $boxes['hero_box']['object_type']);
        $this->assertSame([ 'page' ], $boxes['hero_box']['post_types']);
        $this->assertSame('options-page', $boxes['site_options']['object_type']);
        $this->assertSame([ 'site_settings' ], $boxes['site_options']['option_keys']);
        $this->assertSame('user', $boxes['profile']['object_type']);
        $this->assertFalse($boxes['profile']['values_readable']);

        $fields = array_column($boxes['hero_box']['fields'], null, 'id');
        $this->assertTrue($fields['hero_title']['writable']);
        $this->assertFalse($fields['hero_slides']['writable']);
        $this->assertFalse($fields['hero_pages']['writable']);
        $this->assertTrue($fields['maps_api_key']['secret']);
        $this->assertTrue($fields['smtp_login']['secret']);

        $only = $this->read('list-cmb2-boxes', [ 'object_type' => 'options-page' ])['result'];
        $this->assertSame([ 'site_options' ], array_column($only['boxes'], 'id'));
    }

    public function test_get_values_per_post_with_defaults_and_hidden_secrets(): void
    {
        $this->page_box();
        update_post_meta($this->post_id, 'hero_title', 'Hello');
        update_post_meta($this->post_id, 'hero_tags', [ 'new', 'hot' ]);
        update_post_meta($this->post_id, 'hero_slides', [ [ 'caption' => 'One', 'api_token' => 'tok-SECRET' ] ]);
        update_post_meta($this->post_id, 'maps_api_key', 'AIza-SECRET');
        update_post_meta($this->post_id, 'smtp_login', 'hunter2');

        $out    = $this->read('get-cmb2-values', [ 'box' => 'hero_box', 'post_id' => $this->post_id ]);
        $fields = array_column($out['result']['fields'], null, 'id');

        $this->assertSame('post', $out['result']['object_type']);
        $this->assertSame($this->post_id, $out['result']['post_id']);
        $this->assertSame('Hello', $fields['hero_title']['value']);
        $this->assertFalse($fields['hero_title']['is_default']);
        $this->assertSame('left', $fields['hero_layout']['value']);
        $this->assertTrue($fields['hero_layout']['is_default']);
        $this->assertSame([ 'left' => 'Left', 'center' => 'Center' ], $fields['hero_layout']['options']);
        $this->assertSame([ 'new', 'hot' ], $fields['hero_tags']['value']);
        $this->assertSame('One', $fields['hero_slides']['value'][0]['caption']);
        $this->assertSame('[redacted]', $fields['hero_slides']['value'][0]['api_token']);
        $this->assertSame('[redacted]', $fields['maps_api_key']['value']);
        $this->assertSame('[redacted]', $fields['smtp_login']['value']);
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('SECRET', $json);
        $this->assertStringNotContainsString('hunter2', $json);

        $one = $this->values([ 'box' => 'hero_box', 'post_id' => $this->post_id, 'fields' => [ 'hero_title' ] ]);
        $this->assertSame([ 'hero_title' ], array_keys($one));
    }

    public function test_get_values_refuses_unknown_fields_wrong_posts_and_unsupported_boxes(): void
    {
        $this->page_box();
        $this->boxes['profile'] = new Cmb2_Box_Double('profile', [ 'title' => 'Profile', 'object_types' => [ 'user' ] ], [
            [ 'id' => 'twitter', 'type' => 'text', 'name' => 'Twitter' ],
        ]);
        $post = self::factory()->post->create([ 'post_type' => 'post' ]);

        $this->assertSame('unknown_field', $this->code($this->read('get-cmb2-values', [ 'box' => 'hero_box', 'post_id' => $this->post_id, 'fields' => [ 'nope' ] ])));
        $this->assertSame('cmb2_box_not_found', $this->code($this->read('get-cmb2-values', [ 'box' => 'missing', 'post_id' => $this->post_id ])));
        $this->assertSame('post_id_required', $this->code($this->read('get-cmb2-values', [ 'box' => 'hero_box' ])));
        $this->assertSame('cmb2_box_not_for_post', $this->code($this->read('get-cmb2-values', [ 'box' => 'hero_box', 'post_id' => $post ])));
        $this->assertSame('cmb2_object_type_unsupported', $this->code($this->read('get-cmb2-values', [ 'box' => 'profile' ])));
    }

    public function test_get_values_of_an_options_page_box(): void
    {
        $this->options_box();
        update_option('site_settings', [ 'phone' => '555-0100', 'license_key' => 'LIC-SECRET' ]);

        $out    = $this->read('get-cmb2-values', [ 'box' => 'site_options' ]);
        $fields = array_column($out['result']['fields'], null, 'id');

        $this->assertSame('options-page', $out['result']['object_type']);
        $this->assertSame('site_settings', $out['result']['option_key']);
        $this->assertSame('555-0100', $fields['phone']['value']);
        $this->assertSame('Made with care', $fields['footer_text']['value']);
        $this->assertTrue($fields['footer_text']['is_default']);
        $this->assertSame('[redacted]', $fields['license_key']['value']);
        $this->assertStringNotContainsString('LIC-SECRET', (string) wp_json_encode($out));
        $this->assertSame('unknown_option_key', $this->code($this->read('get-cmb2-values', [ 'box' => 'site_options', 'option_key' => 'other' ])));
    }

    public function test_set_writes_post_meta_through_the_field_save_and_undoes(): void
    {
        $this->opt_in();
        $box = $this->page_box();
        update_post_meta($this->post_id, 'hero_title', 'Hello');

        $out = $this->write([ 'box' => 'hero_box', 'post_id' => $this->post_id, 'field' => 'hero_title', 'value' => 'New <b>title</b>' ]);

        $this->assertArrayHasKey('result', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame(1, $box->saves, 'the value goes through the field\'s own save_field()');
        $this->assertSame('New title', get_post_meta($this->post_id, 'hero_title', true), 'CMB2 sanitizes a text field');
        $this->assertSame('Hello', $out['result']['previous']);
        $this->assertSame('New title', $out['result']['value']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('Hello', get_post_meta($this->post_id, 'hero_title', true));
    }

    public function test_set_of_a_never_saved_field_undoes_to_no_meta(): void
    {
        $this->opt_in();
        $this->page_box();

        $out = $this->write([ 'box' => 'hero_box', 'post_id' => $this->post_id, 'field' => 'hero_layout', 'value' => 'center' ]);
        $this->assertArrayHasKey('result', $out, (string) wp_json_encode($out));
        $this->assertSame('center', get_post_meta($this->post_id, 'hero_layout', true));

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertFalse(metadata_exists('post', $this->post_id, 'hero_layout'));
    }

    public function test_checkbox_and_multicheck_writes_store_cmb2_formats_and_undo(): void
    {
        $this->opt_in();
        $this->page_box();

        $on = $this->write([ 'box' => 'hero_box', 'post_id' => $this->post_id, 'field' => 'hero_dark', 'value' => true ]);
        $this->assertArrayHasKey('result', $on, (string) wp_json_encode($on));
        $this->assertSame('on', get_post_meta($this->post_id, 'hero_dark', true));

        $off = $this->write([ 'box' => 'hero_box', 'post_id' => $this->post_id, 'field' => 'hero_dark', 'value' => false ]);
        $this->assertArrayHasKey('result', $off, (string) wp_json_encode($off));
        $this->assertFalse(metadata_exists('post', $this->post_id, 'hero_dark'), 'an unchecked box removes the meta, as CMB2 does');
        (new Rollback_Operation())->handle([ 'operation_id' => $off['operation_id'] ]);
        $this->assertSame('on', get_post_meta($this->post_id, 'hero_dark', true));
        (new Rollback_Operation())->handle([ 'operation_id' => $on['operation_id'] ]);
        $this->assertFalse(metadata_exists('post', $this->post_id, 'hero_dark'));

        $tags = $this->write([ 'box' => 'hero_box', 'post_id' => $this->post_id, 'field' => 'hero_tags', 'value' => [ 'sale', 'new' ] ]);
        $this->assertArrayHasKey('result', $tags, (string) wp_json_encode($tags));
        $this->assertSame([ 'sale', 'new' ], get_post_meta($this->post_id, 'hero_tags', true));
        (new Rollback_Operation())->handle([ 'operation_id' => $tags['operation_id'] ]);
        $this->assertFalse(metadata_exists('post', $this->post_id, 'hero_tags'));

        $color = $this->write([ 'box' => 'hero_box', 'post_id' => $this->post_id, 'field' => 'hero_color', 'value' => '#AA3300' ]);
        $this->assertArrayHasKey('result', $color, (string) wp_json_encode($color));
        $this->assertSame('#AA3300', get_post_meta($this->post_id, 'hero_color', true));
        (new Rollback_Operation())->handle([ 'operation_id' => $color['operation_id'] ]);
        $this->assertFalse(metadata_exists('post', $this->post_id, 'hero_color'));
    }

    public function test_set_writes_an_options_page_value_and_undoes(): void
    {
        $this->opt_in();
        $this->options_box();
        update_option('site_settings', [ 'phone' => '555-0100' ]);

        $out = $this->write([ 'box' => 'site_options', 'field' => 'footer_text', 'value' => 'Copyright Shop' ]);

        $this->assertArrayHasKey('result', $out, (string) wp_json_encode($out));
        $this->assertSame([ 'phone' => '555-0100', 'footer_text' => 'Copyright Shop' ], get_option('site_settings'));

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame([ 'phone' => '555-0100' ], get_option('site_settings'));
    }

    public function test_first_options_page_write_undoes_to_no_option(): void
    {
        $this->opt_in();
        $this->options_box();

        $out = $this->write([ 'box' => 'site_options', 'option_key' => 'site_settings', 'field' => 'phone', 'value' => '555-0199' ]);

        $this->assertArrayHasKey('result', $out, (string) wp_json_encode($out));
        $this->assertSame([ 'phone' => '555-0199' ], get_option('site_settings'));

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertFalse(get_option('site_settings'));
    }

    public function test_refusals_come_before_any_snapshot(): void
    {
        $this->opt_in();
        $this->page_box([ [ 'id' => 'hero_off', 'type' => 'text', 'name' => 'Not saved', 'save_field' => false ] ]);
        update_post_meta($this->post_id, 'hero_title', 'Hello');
        add_filter('cmb2_override_hero_title_meta_save', '__return_true');
        $before = $this->snapshots();
        $base   = [ 'box' => 'hero_box', 'post_id' => $this->post_id ];

        $this->assertSame('unknown_field', $this->code($this->write($base + [ 'field' => 'nope', 'value' => 'x' ])));
        $this->assertSame('field_not_writable', $this->code($this->write($base + [ 'field' => 'hero_slides', 'value' => 'x' ])), 'group');
        $this->assertSame('field_not_writable', $this->code($this->write($base + [ 'field' => 'hero_pages', 'value' => '2' ])), 'options from a callback');
        $this->assertSame('field_not_writable', $this->code($this->write($base + [ 'field' => 'maps_api_key', 'value' => 'x' ])), 'secret');
        $this->assertSame('field_not_writable', $this->code($this->write($base + [ 'field' => 'hero_off', 'value' => 'x' ])), 'save_field false');
        $this->assertSame('field_not_writable', $this->code($this->write($base + [ 'field' => 'hero_title', 'value' => 'x' ])), 'custom storage through a save override');
        $this->assertSame('invalid_value', $this->code($this->write($base + [ 'field' => 'hero_layout', 'value' => 'right' ])));
        $this->assertSame('invalid_value', $this->code($this->write($base + [ 'field' => 'hero_email', 'value' => 'not an email' ])));
        $this->assertSame('invalid_value', $this->code($this->write($base + [ 'field' => 'hero_color', 'value' => 'red' ])));
        $this->assertSame('invalid_value', $this->code($this->write($base + [ 'field' => 'hero_tags', 'value' => [ 'new', 'gone' ] ])));
        $this->assertSame('invalid_value', $this->code($this->write($base + [ 'field' => 'hero_dark', 'value' => 'maybe' ])));
        $this->assertSame('invalid_value', $this->code($this->write($base + [ 'field' => 'hero_intro', 'value' => [ 'a' ] ])));
        update_post_meta($this->post_id, 'hero_layout', 'left');
        $this->assertSame('no_change', $this->code($this->write($base + [ 'field' => 'hero_layout', 'value' => 'left' ])));

        $this->assertSame($before, $this->snapshots());
        $this->assertSame('Hello', get_post_meta($this->post_id, 'hero_title', true));
    }

    public function test_a_value_cmb2_sanitizes_away_is_put_back_and_leaves_no_undo_point(): void
    {
        $this->opt_in();
        $this->page_box([ [ 'id' => 'hero_code', 'type' => 'text', 'name' => 'Code', 'sanitization_cb' => static fn ($value) => ctype_digit((string) $value) ? $value : '' ] ]);
        update_post_meta($this->post_id, 'hero_code', '42');
        $before = $this->snapshots();

        $out = $this->write([ 'box' => 'hero_box', 'post_id' => $this->post_id, 'field' => 'hero_code', 'value' => 'abc' ]);

        $this->assertSame('cmb2_rejected_value', $this->code($out));
        $this->assertSame('42', get_post_meta($this->post_id, 'hero_code', true));
        $this->assertSame($before, $this->snapshots());
    }

    public function test_an_options_page_box_capability_is_enforced(): void
    {
        $this->opt_in();
        $this->options_box([ 'capability' => 'wpmcp_cmb2_test_cap' ]);

        $this->assertSame('operation_denied', $this->code($this->read('get-cmb2-values', [ 'box' => 'site_options' ])));
        $this->assertSame('operation_denied', $this->code($this->write([ 'box' => 'site_options', 'field' => 'phone', 'value' => '1' ])));
        $listed = array_column($this->read('list-cmb2-boxes')['result']['boxes'], 'id');
        $this->assertNotContains('site_options', $listed);
    }
}
