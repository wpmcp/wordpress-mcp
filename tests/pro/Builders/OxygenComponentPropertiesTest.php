<?php

declare(strict_types=1);

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Get_Builder_Content, Oxygen_Page, Update_Builder_Content};

/**
 * Issue #805: Oxygen 6 Component editable properties. A Component declares
 * them on its nodes (data.properties.meta.component.editableProperties, each
 * {enabled?, label, controlPath, propertyKey}); an instance on a page keeps
 * the values in content.content.block.properties keyed by propertyKey and
 * the targets list {nodeId, controlPath, propertyKey} the renderer applies
 * (Breakdance\Components\replaceNodePropertiesWithEditedPropertiesFromComponent()).
 *
 * Oxygen is not installed here: rows are stored raw in the Oxygen 6.1 shape
 * and its presence is toggled through wpmcp_oxygen_active.
 */
final class OxygenComponentPropertiesTest extends \WP_UnitTestCase
{
    /** A Component with three declared properties, one of them disabled. */
    private const COMPONENT_TREE = '{"root":{"id":1,"data":{"type":"root","properties":{}},"children":['
        . '{"id":100,"data":{"type":"OxygenElements\\\\Container","properties":null},"children":['
        . '{"id":101,"data":{"type":"OxygenElements\\\\Text","properties":{"content":{"content":{"text":"Default heading"}},'
        . '"meta":{"component":{"editableProperties":[{"enabled":true,"label":"Heading","controlPath":"content.content.text","propertyKey":"heading"}]}}}},"children":[],"_parentId":100},'
        . '{"id":102,"data":{"type":"OxygenElements\\\\TextLink","properties":{"content":{"content":{"text":"Go","url":"#"}},'
        . '"meta":{"component":{"editableProperties":[{"label":"Link","controlPath":"content.content.url","propertyKey":"link"},'
        . '{"enabled":false,"label":"Old label","controlPath":"content.content.text","propertyKey":"old"}]}}}},"children":[],"_parentId":100}'
        . '],"_parentId":1}]},"_nextNodeId":103,"status":"exported"}';

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $GLOBALS['wpmcp_breakdance_cache_calls'] = [];
        add_filter('wpmcp_oxygen_active', '__return_true');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_oxygen_active');
        parent::tearDown();
    }

    private function raw_meta(int $post_id, string $key, string $value): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value]);
        wp_cache_delete($post_id, 'post_meta');
    }

    private function raw(int $post_id): ?string
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_oxygen_data'", $post_id));
    }

    private function post(string $type, string $tree): int
    {
        $id = self::factory()->post->create(['post_type' => $type, 'post_status' => 'publish']);
        $this->raw_meta($id, '_oxygen_data', (string) wp_json_encode(['tree_json_string' => $tree]));

        return $id;
    }

    private function component(): int
    {
        return $this->post('oxygen_block', self::COMPONENT_TREE);
    }

    /** A page holding one instance of the Component (node 100) and a Text node (101). */
    private function page(int $component, string $properties = '{}'): int
    {
        return $this->post('page', '{"root":{"id":1,"data":{"type":"root","properties":{}},"children":['
            . '{"id":100,"data":{"type":"OxygenElements\\\\Component","properties":{"content":{"content":{"block":{"componentId":' . $component . ',"targets":[],"properties":' . $properties . '}}}}},"children":[],"_parentId":1},'
            . '{"id":101,"data":{"type":"OxygenElements\\\\Text","properties":{"content":{"content":{"text":"Untouched"}}}},"children":[],"_parentId":1}'
            . ']},"_nextNodeId":102,"status":"exported"}');
    }

    private function state(int $post_id): array
    {
        $post = get_post($post_id);
        return [$post->post_content, $post->post_status, get_post_meta($post_id)];
    }

    private function set(int $post_id, array $args)
    {
        $hash = (new Get_Builder_Content())->handle(['post_id' => $post_id])['hash'];

        return (new Update_Builder_Content())->handle($args + ['post_id' => $post_id, 'builder' => 'oxygen', 'operation' => 'set_properties', 'path' => '100', 'expected_hash' => $hash]);
    }

    private function block(int $post_id): object
    {
        $tree = json_decode((string) json_decode((string) $this->raw($post_id))->tree_json_string);

        return $tree->root->children[0]->data->properties->content->content->block;
    }

    public function test_properties_scope_lists_what_the_component_declares(): void
    {
        $component = $this->component();

        $out = (new Get_Builder_Content())->handle(['builder' => 'oxygen', 'scope' => 'properties', 'post_id' => $component]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame($component, $out['component_id']);
        $this->assertSame([
            ['key' => 'heading', 'label' => 'Heading', 'control_path' => 'content.content.text', 'node_id' => 101, 'enabled' => true],
            ['key' => 'link', 'label' => 'Link', 'control_path' => 'content.content.url', 'node_id' => 102, 'enabled' => true],
            ['key' => 'old', 'label' => 'Old label', 'control_path' => 'content.content.text', 'node_id' => 102, 'enabled' => false],
        ], $out['properties']);
        $this->assertSame(Oxygen_Page::hash($component), $out['hash']);
    }

    public function test_properties_scope_refuses_a_post_that_is_not_a_component(): void
    {
        $page = $this->page($this->component());

        $out = (new Get_Builder_Content())->handle(['builder' => 'oxygen', 'scope' => 'properties', 'post_id' => $page]);

        $this->assertWPError($out);
        $this->assertSame('invalid_oxygen_request', $out->get_error_code());
    }

    public function test_set_properties_stores_values_and_targets_the_way_oxygen_reads_them_and_rolls_back(): void
    {
        $component = $this->component();
        $page      = $this->page($component);
        $before    = $this->state($page);
        $raw       = (string) $this->raw($page);

        $out = $this->set($page, ['attrs' => ['heading' => 'Welcome', 'link' => '/contact']]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('100', $out['path']);
        $this->assertSame(Oxygen_Page::hash($page), $out['hash']);
        $block = $this->block($page);
        $this->assertSame($component, $block->componentId);
        $this->assertSame(['heading' => 'Welcome', 'link' => '/contact'], (array) $block->properties);
        $this->assertSame([
            ['nodeId' => 101, 'controlPath' => 'content.content.text', 'propertyKey' => 'heading'],
            ['nodeId' => 102, 'controlPath' => 'content.content.url', 'propertyKey' => 'link'],
            ['nodeId' => 102, 'controlPath' => 'content.content.text', 'propertyKey' => 'old'],
        ], json_decode((string) wp_json_encode($block->targets), true), 'Every declared property is a target, as the editor computes them.');
        $this->assertStringContainsString('{"id":101,"data":{"type":"OxygenElements\\\\Text","properties":{"content":{"content":{"text":"Untouched"}}}},"children":[],"_parentId":1}', (string) json_decode((string) $this->raw($page))->tree_json_string, 'Other nodes keep their bytes.');
        $this->assertNotSame($raw, $this->raw($page));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($page));
        $this->assertSame($raw, $this->raw($page));
    }

    public function test_null_removes_a_value_and_an_object_value_replaces_the_old_one(): void
    {
        $page = $this->page($this->component(), '{"heading":"Old","link":{"url":"/a","x":1}}');

        $out = $this->set($page, ['attrs' => ['heading' => null, 'link' => ['url' => '/b']]]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame(['link' => ['url' => '/b']], json_decode((string) wp_json_encode($this->block($page)->properties), true));

        $out = $this->set($page, ['attrs' => ['link' => null]]);
        $this->assertIsArray($out);
        $this->assertStringContainsString('"properties":{}', (string) json_decode((string) $this->raw($page))->tree_json_string, 'An emptied map stays an object.');
    }

    public function test_bad_requests_are_refused_and_write_nothing(): void
    {
        $component = $this->component();
        $page      = $this->page($component);
        $missing   = $this->page(999999);
        $before    = $this->state($page);

        $cases = [
            [['attrs' => ['nope' => 'x']], 'heading, link'],
            [['attrs' => ['old' => 'x']], 'disabled'],
            [['attrs' => []], 'attrs'],
            [['attrs' => ['heading' => 'x'], 'path' => '101'], 'not a Component'],
            [['attrs' => ['heading' => 'x'], 'path' => '999'], ''],
        ];
        foreach ($cases as [$args, $needle]) {
            $out = $this->set($page, $args);
            $this->assertWPError($out, (string) wp_json_encode($args));
            $this->assertSame('invalid_oxygen_request', $out->get_error_code(), (string) wp_json_encode($args));
            $this->assertStringContainsString($needle, $out->get_error_message());
        }
        $this->assertSame($before, $this->state($page));

        $out = $this->set($missing, ['attrs' => ['heading' => 'x']]);
        $this->assertSame('invalid_oxygen_request', $out->get_error_code());
    }

    public function test_set_properties_needs_the_current_hash_and_oxygen_active(): void
    {
        $page   = $this->page($this->component());
        $before = $this->state($page);
        $base   = ['post_id' => $page, 'builder' => 'oxygen', 'operation' => 'set_properties', 'path' => '100', 'attrs' => ['heading' => 'x']];

        $this->assertSame('missing_expected_hash', (new Update_Builder_Content())->handle($base)->get_error_code());
        $this->assertSame('stale_expected_hash', (new Update_Builder_Content())->handle($base + ['expected_hash' => hash('sha256', 'x')])->get_error_code());

        remove_all_filters('wpmcp_oxygen_active');
        add_filter('wpmcp_oxygen_active', '__return_false');
        $out = $this->set($page, ['attrs' => ['heading' => 'x']]);
        $this->assertSame('plugin_missing', $out->get_error_code());
        $this->assertStringContainsString('Install and activate Oxygen 6', $out->get_error_message());
        $this->assertSame($before, $this->state($page));
    }

    public function test_a_user_who_cannot_edit_the_page_is_refused(): void
    {
        $page   = $this->page($this->component());
        $before = $this->state($page);
        $hash   = Oxygen_Page::hash($page);
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $out = (new Update_Builder_Content())->handle(['post_id' => $page, 'builder' => 'oxygen', 'operation' => 'set_properties', 'path' => '100', 'attrs' => ['heading' => 'x'], 'expected_hash' => $hash]);

        $this->assertSame('forbidden', $out->get_error_code());
        $this->assertSame($before, $this->state($page));
    }
}
