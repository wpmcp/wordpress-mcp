<?php

declare(strict_types=1);

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #650: Blocksy content blocks (hooks) as the layout ops of the
 * Blocksy family, against the ct_content_block post type and the
 * blocksy_post_meta_options meta the Blocksy options framework stores and
 * blocksy_get_post_options() reads, with the template filtered to Blocksy,
 * the companion's presence filtered on and its post type registered.
 */
class ThemeLayoutsBlocksyTest extends \WP_UnitTestCase
{
    private const POST_TYPE = 'ct_content_block';
    private const META      = 'blocksy_post_meta_options';

    private \Closure $template;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_theme_write', '__return_true');
        add_filter('wpmcp_theme_pro_active', '__return_true');
        $this->template = static fn (): string => 'blocksy';
        add_filter('template', $this->template);
        register_post_type(self::POST_TYPE, [ 'public' => false, 'show_ui' => true ]);
    }

    protected function tearDown(): void
    {
        remove_filter('template', $this->template);
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        remove_filter('wpmcp_theme_pro_active', '__return_true');
        remove_filter('wpmcp_theme_pro_active', '__return_false');
        unregister_post_type(self::POST_TYPE);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    /** @return array<string,mixed> */
    private function write(string $op, array $args): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    /** @return array<string,mixed> */
    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** @return string[] */
    private function names(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], 'name');
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
    }

    /** @return array<string,mixed> */
    private function options(int $id): array
    {
        $value = get_post_meta($id, self::META, true);
        return is_array($value) ? $value : [];
    }

    public function test_layout_ops_register_for_the_blocksy_family(): void
    {
        $names = $this->names();
        foreach ([ 'list-blocksy-layouts', 'get-blocksy-layout', 'list-blocksy-hooks', 'create-blocksy-layout', 'update-blocksy-layout', 'set-blocksy-layout-display-rules' ] as $op) {
            $this->assertContains($op, $names);
        }
        Gate::set_pro_for_tests(false);
        $this->assertNotContains('list-blocksy-layouts', $this->names());
    }

    public function test_missing_companion_and_disabled_module_are_refused(): void
    {
        remove_filter('wpmcp_theme_pro_active', '__return_true');
        add_filter('wpmcp_theme_pro_active', '__return_false');
        $out = $this->read('list-blocksy-layouts');
        $this->assertContains($out['error']['code'], [ 'plugin_missing', 'plugin_inactive' ]);
        $this->assertStringContainsString('Blocksy Companion Pro', $out['error']['message']);

        remove_filter('wpmcp_theme_pro_active', '__return_false');
        add_filter('wpmcp_theme_pro_active', '__return_true');
        unregister_post_type(self::POST_TYPE);
        $this->assertSame('module_disabled', $this->read('list-blocksy-layouts')['error']['code']);
    }

    public function test_hook_catalog_lists_blocksy_and_core_hooks(): void
    {
        $hooks = $this->ok($this->read('list-blocksy-hooks', [ 'type' => 'hook' ]))['result'];
        $names = [];
        foreach ($hooks['hooks'] ?? $hooks['groups'] ?? [] as $group) {
            foreach ($group['hooks'] as $hook) {
                $names[] = $hook['name'];
            }
        }
        $this->assertContains('blocksy:header:before', $names);
        $this->assertContains('blocksy:content:top', $names);
        $this->assertContains('wp_footer', $names);
    }

    public function test_create_stores_what_blocksy_reads_and_rollback_trashes_it(): void
    {
        $out = $this->ok($this->write('create-blocksy-layout', [
            'title'    => 'Promo bar',
            'type'     => 'hook',
            'hook'     => 'blocksy:header:before',
            'priority' => 5,
            'content'  => '<!-- wp:paragraph --><p>Sale</p><!-- /wp:paragraph -->',
            'status'   => 'publish',
            'rules'    => [ 'include' => [ [ 'rule' => 'everywhere' ] ], 'exclude' => [ [ 'rule' => 'search' ] ] ],
        ]));

        $id = (int) $out['result']['layout']['id'];
        $this->assertSame(self::POST_TYPE, get_post_type($id));
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('<!-- wp:paragraph --><p>Sale</p><!-- /wp:paragraph -->', get_post_field('post_content', $id));
        $options = $this->options($id);
        $this->assertSame('hook', $options['template_type']);
        $this->assertSame('blocksy:header:before', $options['location']);
        $this->assertSame(5, $options['priority']);
        $this->assertSame([ [ 'type' => 'include', 'rule' => 'everywhere' ], [ 'type' => 'exclude', 'rule' => 'search' ] ], $options['conditions']);

        $view = $this->ok($this->read('get-blocksy-layout', [ 'layout_id' => $id ]))['result']['layout'];
        $this->assertSame('blocksy:header:before', $view['hook']);
        $this->assertSame(5, $view['priority']);
        $this->assertSame([ 'include' => [ [ 'rule' => 'everywhere' ] ], 'exclude' => [ [ 'rule' => 'search' ] ] ], $view['rules']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_ids'][0]));
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_custom_hook_is_stored_as_blocksy_stores_it(): void
    {
        $id = (int) $this->ok($this->write('create-blocksy-layout', [ 'title' => 'C', 'type' => 'hook', 'hook' => 'my_theme_hook', 'custom_hook' => true ]))['result']['layout']['id'];
        $options = $this->options($id);
        $this->assertSame('custom_hook', $options['location']);
        $this->assertSame('my_theme_hook', $options['custom_location']);
        $view = $this->ok($this->read('get-blocksy-layout', [ 'layout_id' => $id ]))['result']['layout'];
        $this->assertSame('my_theme_hook', $view['hook']);
        $this->assertTrue($view['custom_hook']);
        $this->assertSame('draft', get_post_status($id));
    }

    public function test_update_and_rules_roll_back_exactly_and_keep_other_options(): void
    {
        $id = self::factory()->post->create([ 'post_type' => self::POST_TYPE, 'post_title' => 'Head', 'post_content' => '<p>Old</p>', 'post_status' => 'draft' ]);
        update_post_meta($id, self::META, [ 'template_type' => 'hook', 'location' => 'blocksy:content:top', 'priority' => 10, 'conditions' => [ [ 'type' => 'include', 'rule' => 'everywhere' ] ], 'visibility' => [ 'desktop' => true ] ]);
        $meta_before = get_post_meta($id);

        $up = $this->ok($this->write('update-blocksy-layout', [ 'layout_id' => $id, 'title' => 'Head 2', 'content' => '<p>New</p>', 'hook' => 'blocksy:footer:before', 'priority' => 20 ]));
        $this->assertSame('Head 2', get_the_title($id));
        $this->assertSame('blocksy:footer:before', $this->options($id)['location']);
        $this->assertSame([ 'desktop' => true ], $this->options($id)['visibility'], 'Options the ops do not own are kept');

        $rules = $this->ok($this->write('set-blocksy-layout-display-rules', [ 'layout_id' => $id, 'rules' => [ 'include' => [ [ 'rule' => 'single_page' ] ] ] ]));
        $this->assertSame([ [ 'type' => 'include', 'rule' => 'single_page' ] ], $this->options($id)['conditions']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $rules['operation_id']));
        $this->assertSame([ [ 'type' => 'include', 'rule' => 'everywhere' ] ], $this->options($id)['conditions']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $up['operation_id']));
        $this->assertSame('Head', get_the_title($id));
        $this->assertSame('<p>Old</p>', get_post_field('post_content', $id));
        $this->assertEquals($meta_before, get_post_meta($id));
    }

    public function test_refusals_take_no_snapshot(): void
    {
        $before = $this->snapshot_count();
        $cases  = [
            'unknown_hook'          => [ 'title' => 'A', 'type' => 'hook', 'hook' => 'blocksy:typo' ],
            'hook_required'         => [ 'title' => 'A', 'type' => 'hook' ],
            'layout_type_read_only' => [ 'title' => 'A', 'type' => 'maintenance' ],
            'invalid_display_rules' => [ 'title' => 'A', 'type' => 'hook', 'hook' => 'wp_footer', 'rules' => [ 'include' => [ [ 'rule' => 'no such rule!' ] ] ] ],
            'blocked_hook'          => [ 'title' => 'A', 'type' => 'hook', 'hook' => 'init', 'custom_hook' => true ],
        ];
        foreach ($cases as $code => $args) {
            $out = $this->write('create-blocksy-layout', $args);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        $this->assertSame('invalid_display_rules', $this->write('create-blocksy-layout', [ 'title' => 'A', 'type' => 'hook', 'hook' => 'wp_footer', 'rules' => [ 'sometimes' => [] ] ])['error']['code']);
        $this->assertSame($before, $this->snapshot_count());
        $this->assertSame([], get_posts([ 'post_type' => self::POST_TYPE, 'post_status' => 'any' ]));
    }

    public function test_code_block_content_is_not_editable(): void
    {
        $id = self::factory()->post->create([ 'post_type' => self::POST_TYPE, 'post_title' => 'Code' ]);
        update_post_meta($id, self::META, [ 'template_type' => 'hook', 'location' => 'wp_head', 'has_inline_code' => 'yes' ]);
        $this->assertSame('code_layout', $this->write('update-blocksy-layout', [ 'layout_id' => $id, 'content' => '<p>x</p>' ])['error']['code']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        $this->assertSame('opt_in_required', $this->write('create-blocksy-layout', [ 'title' => 'X', 'type' => 'hook', 'hook' => 'wp_footer' ])['error']['code']);
    }
}
