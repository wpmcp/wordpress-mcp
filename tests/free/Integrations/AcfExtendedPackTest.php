<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Tests\Support\Acfe_Module_Double;

require_once __DIR__ . '/../../support/acfe-double.php';

/**
 * Issue #1089: ACF Extended reads on the theme pair. Its options pages
 * (its own acfe-dop items and ACF's acf-ui-options-page posts), dynamic
 * forms and block types are listed and read one by one with secrets hidden,
 * and a field group's settings are read with the ones ACF Extended adds.
 * Reads only: nothing here writes.
 */
final class AcfExtendedPackTest extends \WP_UnitTestCase
{
    /** @var array<string, Acfe_Module_Double> */
    private array $modules = [];

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('wpmcp_acfe_active', '__return_true');
        add_filter('wpmcp_acfe_modules', [ $this, 'modules' ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_acfe_active');
        remove_all_filters('wpmcp_acfe_modules');
        $this->modules = [];
        parent::tearDown();
    }

    /** @return array<string, Acfe_Module_Double> */
    public function modules(): array
    {
        return $this->modules;
    }

    private function seed_modules(bool $block_types_active = true): void
    {
        $this->modules['options_page'] = new Acfe_Module_Double('options_page', 'acfe-dop', [
            [ 'ID' => 11, 'name' => 'theme-settings', 'label' => 'Theme settings', 'menu_slug' => 'theme-settings', 'page_title' => 'Theme settings', 'menu_title' => 'Theme', 'capability' => 'edit_posts', 'parent_slug' => '', 'post_id' => 'options', 'autoload' => false, 'active' => true ],
        ]);
        $this->modules['form'] = new Acfe_Module_Double('form', 'acfe-form', [
            [
                'ID'           => 21,
                'name'         => 'contact',
                'label'        => 'Contact',
                'title'        => 'Contact',
                'active'       => true,
                'field_groups' => [ 'group_contact' ],
                'actions'      => [
                    [ 'action' => 'email', 'name' => 'notify', 'email' => [ 'to' => 'shop@example.com', 'subject' => 'New message', 'content' => 'Hi' ] ],
                    [ 'action' => 'custom', 'name' => 'crm', 'api_key' => 'crm-SECRET', 'settings' => [ 'webhook_token' => 'tok-SECRET', 'list' => 'leads' ] ],
                ],
                'settings'     => [ 'honeypot' => true ],
            ],
        ]);
        $this->modules['block_type'] = new Acfe_Module_Double('block_type', 'acfe-dbt', [
            [ 'ID' => 0, 'name' => 'hero', 'label' => 'Hero', 'title' => 'Hero', 'category' => 'design', 'post_types' => [ 'page' ], 'mode' => 'preview', 'render_template' => 'blocks/hero.php', 'active' => true, 'description' => 'Big banner' ],
        ], $block_types_active);
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    public function test_the_reads_are_on_the_theme_pair(): void
    {
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');

        foreach ([ 'list-acfe-items', 'get-acfe-item', 'get-acfe-field-group-settings' ] as $op) {
            $this->assertSame('read', $ops[ $op ]['mode'], $op);
            $this->assertTrue($ops[ $op ]['enabled'], $op);
        }
    }

    public function test_without_acf_extended_the_ops_name_it(): void
    {
        remove_all_filters('wpmcp_acfe_active');
        add_filter('wpmcp_acfe_active', '__return_false');

        $out = $this->read('list-acfe-items', [ 'kind' => 'form' ]);

        $this->assertSame('acfe_inactive', $this->code($out));
        $this->assertStringContainsString('ACF Extended', $out['error']['message']);
    }

    public function test_lists_options_pages_from_acf_extended_and_from_acf_ui_posts(): void
    {
        $this->seed_modules();
        self::factory()->post->create([
            'post_type'    => 'acf-ui-options-page',
            'post_status'  => 'publish',
            'post_title'   => 'Shop options',
            'post_name'    => 'ui_options_page_shop',
            'post_content' => maybe_serialize([ 'menu_slug' => 'shop-options', 'page_title' => 'Shop options', 'parent_slug' => 'options-general.php', 'capability' => 'manage_options', 'post_id' => 'shop', 'active' => true ]),
        ]);

        $out = $this->read('list-acfe-items', [ 'kind' => 'options_page' ]);
        $this->assertArrayHasKey('result', $out, (string) wp_json_encode($out));
        $items = array_column($out['result']['items'], null, 'name');

        $this->assertTrue($out['result']['module_active']);
        $this->assertSame([ 'theme-settings', 'shop-options' ], array_keys($items));
        $this->assertSame('acfe-dop', $items['theme-settings']['source']);
        $this->assertSame('Theme settings', $items['theme-settings']['title']);
        $this->assertSame('options', $items['theme-settings']['post_id']);
        $this->assertSame('acf-ui-options-page', $items['shop-options']['source']);
        $this->assertSame('options-general.php', $items['shop-options']['parent_slug']);
        $this->assertSame('shop', $items['shop-options']['post_id']);
    }

    public function test_lists_forms_and_block_types(): void
    {
        $this->seed_modules();

        $forms = $this->read('list-acfe-items', [ 'kind' => 'form' ])['result'];
        $this->assertSame(1, $forms['count']);
        $this->assertSame('contact', $forms['items'][0]['name']);
        $this->assertSame([ 'group_contact' ], $forms['items'][0]['field_groups']);
        $this->assertSame([ 'email', 'custom' ], $forms['items'][0]['actions']);
        $this->assertStringNotContainsString('SECRET', (string) wp_json_encode($forms));

        $blocks = $this->read('list-acfe-items', [ 'kind' => 'block_type' ])['result'];
        $this->assertSame('hero', $blocks['items'][0]['name']);
        $this->assertSame('design', $blocks['items'][0]['category']);
        $this->assertSame([ 'page' ], $blocks['items'][0]['post_types']);
        $this->assertSame('local', $blocks['items'][0]['stored_in'], 'an item with no post lives in a local PHP or JSON file');
    }

    public function test_an_inactive_module_lists_nothing_and_says_so(): void
    {
        $this->seed_modules(false);

        $out = $this->read('list-acfe-items', [ 'kind' => 'block_type' ])['result'];

        $this->assertFalse($out['module_active']);
        $this->assertSame([], $out['items']);
    }

    public function test_get_item_reads_one_item_with_secrets_hidden(): void
    {
        $this->seed_modules();

        $out = $this->read('get-acfe-item', [ 'kind' => 'form', 'name' => 'contact' ]);
        $this->assertArrayHasKey('result', $out, (string) wp_json_encode($out));
        $item = $out['result']['item'];

        $this->assertSame('shop@example.com', $item['actions'][0]['email']['to']);
        $this->assertSame('[redacted]', $item['actions'][1]['api_key']);
        $this->assertSame('[redacted]', $item['actions'][1]['settings']['webhook_token']);
        $this->assertSame('leads', $item['actions'][1]['settings']['list']);
        $this->assertStringNotContainsString('SECRET', (string) wp_json_encode($out));

        $this->assertSame('acfe_item_not_found', $this->code($this->read('get-acfe-item', [ 'kind' => 'form', 'name' => 'missing' ])));
    }

    public function test_field_group_settings_include_what_acf_extended_adds(): void
    {
        if (! function_exists('acf_add_local_field_group') || ! function_exists('acf_get_field_group')) {
            $this->markTestSkipped('ACF not active');
        }
        acf_add_local_field_group([
            'key'           => 'group_wpmcp_acfe_settings',
            'title'         => 'ACFE settings group',
            'fields'        => [ [ 'key' => 'field_wpmcp_acfe_one', 'label' => 'One', 'name' => 'wpmcp_acfe_one', 'type' => 'text' ] ],
            'location'      => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ] ] ],
            'acfe'          => [ 'display_title' => 'Shown title', 'permissions' => [ 'administrator' ], 'note' => 'Edit with care', 'meta' => [ [ 'key' => 'client_secret', 'value' => 'cs-SECRET' ] ] ],
            'acfe_autosync' => [ 'json' ],
        ]);

        $out = $this->read('get-acfe-field-group-settings', [ 'key' => 'group_wpmcp_acfe_settings' ]);
        $this->assertArrayHasKey('result', $out, (string) wp_json_encode($out));
        $result = $out['result'];

        $this->assertSame('ACFE settings group', $result['title']);
        $this->assertSame('Shown title', $result['acfe']['display_title']);
        $this->assertSame([ 'administrator' ], $result['acfe']['permissions']);
        $this->assertSame([ 'json' ], $result['acfe_legacy']['acfe_autosync']);
        $this->assertArrayNotHasKey('fields', $result['settings']);
        $this->assertSame(1, $result['field_count']);
        $this->assertStringNotContainsString('cs-SECRET', (string) wp_json_encode($out));

        $this->assertSame('acf_field_group_not_found', $this->code($this->read('get-acfe-field-group-settings', [ 'key' => 'group_missing' ])));
    }
}
