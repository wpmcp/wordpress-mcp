<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Detect_Builder, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #635: the Oxygen 6 adapter against a real Oxygen 6.
 *
 * It runs only in the local gate's live Oxygen leg (bin/test-local.sh
 * --live-oxygen), which sets WPMCP_LIVE_OXYGEN=1, copies the Oxygen plugin
 * from WPMCP_OXYGEN_SRC onto a separate WordPress install (Oxygen is not on
 * wordpress.org) and loads it in tests/bootstrap.php.
 *
 * A page saved through Oxygen's own storage API must be detected, read with
 * its version and hash, edited node by node against that hash, filled from
 * a Component Oxygen stored, restored from a revision Oxygen made, and must
 * still load and render through Oxygen after each edit; a node saved as a
 * Component must be one Oxygen renders; every write must roll back to the
 * exact row Oxygen wrote. The element catalog comes from Oxygen's classes.
 *
 * Everywhere else Oxygen is absent and the test is skipped; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group oxygen-live
 */
class OxygenLiveTest extends \WP_UnitTestCase
{
    private const TREE = '{"root":{"id":1,"data":{"type":"root","properties":{}},"children":['
        . '{"id":100,"data":{"type":"OxygenElements\\\\Container","properties":null},"children":['
        . '{"id":101,"data":{"type":"OxygenElements\\\\Text","properties":{"content":{"content":{"text":"Saved by Oxygen"}}}},"children":[],"_parentId":100},'
        . '{"id":102,"data":{"type":"OxygenElements\\\\Text","properties":{"content":{"content":{"text":"C:\\\\Sites and \\"quotes\\""}}}},"children":[],"_parentId":100}'
        . '],"_parentId":1}]},"_nextNodeId":103,"status":"exported"}';

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('BREAKDANCE_MODE') || 'oxygen' !== BREAKDANCE_MODE || ! function_exists('Breakdance\\Data\\set_meta')) {
            $this->markTestSkipped('Needs the real Oxygen 6 (bin/test-local.sh --live-oxygen, WPMCP_OXYGEN_SRC).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** A post saved the way Oxygen's own save_document() stores it. */
    private function oxygen_post(string $type = 'page', string $tree = self::TREE): int
    {
        $id = self::factory()->post->create(['post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Oxygen ' . $type]);
        \Breakdance\Data\set_meta($id, '_oxygen_data', ['tree_json_string' => $tree]);
        \Breakdance\Render\generateCacheForPost($id);

        return $id;
    }

    private function raw(int $post_id, string $key): ?string
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key));
    }

    /** The document as Oxygen's builder loads it. */
    private function loaded(int $post_id): array
    {
        $tree = \Breakdance\Data\get_tree($post_id);
        $this->assertIsArray($tree, 'Oxygen could not load the document');

        return $tree;
    }

    private function read(int $post_id): array
    {
        $out = (new Get_Builder_Content())->handle(['post_id' => $post_id]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        return $out;
    }

    private function update(int $post_id, array $args)
    {
        $out = (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'oxygen', 'expected_hash' => $this->read($post_id)['hash']] + $args);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        return $out;
    }

    public function test_a_page_saved_by_oxygen_is_read_edited_against_its_hash_and_still_renders(): void
    {
        $id  = $this->oxygen_post();
        $raw = $this->raw($id, '_oxygen_data');

        $this->assertSame('oxygen', (new Detect_Builder())->handle(['post_id' => $id])['builder']);
        $read = $this->read($id);
        $this->assertTrue($read['plugin_active']);
        $this->assertSame(__BREAKDANCE_VERSION, $read['plugin_version']);
        $this->assertSame(hash('sha256', (string) $raw), $read['hash']);

        $stale = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'oxygen', 'operation' => 'remove', 'path' => '102', 'expected_hash' => hash('sha256', 'x')]);
        $this->assertSame('stale_expected_hash', $stale->get_error_code());

        $session = wp_generate_uuid4();
        $this->update($id, ['operation' => 'update', 'path' => '101', 'attrs' => ['content' => ['content' => ['text' => 'Edited through wpmcp']]], 'session_id' => $session]);
        $this->update($id, ['operation' => 'move', 'path' => '102', 'to' => '', 'index' => 0, 'session_id' => $session]);

        $tree = $this->loaded($id);
        $this->assertSame(102, $tree['root']['children'][0]['id']);
        $this->assertSame('Edited through wpmcp', $tree['root']['children'][1]['children'][0]['data']['properties']['content']['content']['text']);
        $this->assertNotNull($this->raw($id, '_oxygen_css_file_paths_cache'), 'Oxygen rebuilt its CSS cache for the page.');
        $html = (string) \Breakdance\Render\render($id);
        $this->assertStringContainsString('Edited through wpmcp', $html);
        $this->assertStringNotContainsString('Saved by Oxygen', $html);

        Rollback_Service::restore_session($session);
        $this->assertSame($raw, $this->raw($id, '_oxygen_data'));
        $this->assertStringContainsString('Saved by Oxygen', (string) \Breakdance\Render\render($id));
    }

    public function test_a_node_saved_as_a_component_renders_where_it_is_placed(): void
    {
        $page = $this->oxygen_post();

        $saved = $this->update($page, ['operation' => 'save_template', 'path' => '100', 'element' => ['title' => 'Hero Component']]);
        $component = get_post($saved['template_id']);
        $this->assertSame(BREAKDANCE_BLOCK_POST_TYPE, $component->post_type);
        $this->assertSame('publish', $component->post_status);
        $this->assertStringContainsString('Saved by Oxygen', (string) \Breakdance\Render\render($component->ID));

        $listed = (new Get_Builder_Content())->handle(['builder' => 'oxygen', 'scope' => 'templates']);
        $this->assertContains($component->ID, array_column($listed['templates'], 'id'));

        $target = $this->oxygen_post('page', str_replace('Saved by Oxygen', 'Target page', self::TREE));
        $before = $this->raw($target, '_oxygen_data');
        $placed = $this->update($target, ['operation' => 'add', 'to' => '', 'element' => ['component' => $component->ID]]);
        $html   = (string) \Breakdance\Render\render($target);
        $this->assertStringContainsString('Target page', $html);
        $this->assertStringContainsString('Saved by Oxygen', $html, 'The Component renders inside the page.');

        $copied = $this->update($target, ['operation' => 'add', 'to' => '100', 'index' => 0, 'element' => ['template' => $component->ID]]);
        $this->assertSame('Saved by Oxygen', $this->loaded($target)['root']['children'][0]['children'][0]['children'][0]['data']['properties']['content']['content']['text']);

        Rollback_Service::restore_operation($copied['operation_id']);
        Rollback_Service::restore_operation($placed['operation_id']);
        $this->assertSame($before, $this->raw($target, '_oxygen_data'));
        Rollback_Service::restore_operation($saved['operation_id']);
        $this->assertSame('trash', get_post_status($component->ID));
    }

    public function test_a_revision_oxygen_made_is_listed_and_restored(): void
    {
        $id = $this->oxygen_post();
        // Oxygen's save_document() ends with this call; its revisions hook
        // copies the data row onto the new revision.
        wp_update_post(['ID' => $id, 'post_content' => 'revision one']);
        $revisions = $this->read($id)['revisions'];
        $this->assertNotEmpty($revisions, 'Oxygen copied its data row onto the revision.');

        $this->update($id, ['operation' => 'remove', 'path' => '100']);
        $this->assertSame([], $this->loaded($id)['root']['children']);

        $out = $this->update($id, ['operation' => 'restore', 'path' => (string) $revisions[0]['id']]);
        $this->assertStringContainsString('Saved by Oxygen', (string) \Breakdance\Render\render($id));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame([], $this->loaded($id)['root']['children']);
    }

    public function test_the_catalog_lists_oxygen_elements(): void
    {
        $out = (new Get_Builder_Content())->handle(['builder' => 'oxygen', 'scope' => 'catalog']);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $types = array_column($out['elements'], 'type');
        $this->assertContains('OxygenElements\\Text', $types);
        $this->assertContains('OxygenElements\\Component', $types);
    }
    /**
     * Issue #805: design writes land where Oxygen's own readers find them,
     * a selector written against a variable renders as that variable, and
     * rollback puts back the exact rows Oxygen's savers wrote.
     */
    public function test_design_writes_are_read_back_by_oxygen_and_roll_back(): void
    {
        \Breakdance\Variables\saveVariables((string) wp_json_encode(['variables' => [[
            'id' => 'live-var-1', 'cssVariableName' => 'live-brand', 'type' => 'color', 'label' => 'Live brand', 'value' => '#123456', 'collection' => 'Default',
        ]], 'collections' => ['Default']]));
        \Breakdance\BreakdanceOxygen\Selectors\saveSelectors((string) wp_json_encode(['selectors' => [], 'collections' => ['Default']]));
        $raw_variables = get_option('oxygen_variables_json_string');
        $raw_selectors = get_option('oxygen_oxy_selectors_json_string');

        $read = (new Get_Builder_Content())->handle(['builder' => 'oxygen', 'scope' => 'design_system']);
        $this->assertIsArray($read);

        $variable = (new Update_Builder_Content())->handle([
            'builder' => 'oxygen', 'scope' => 'design_system', 'operation' => 'add', 'to' => 'variables',
            'element' => ['label' => 'Live gap', 'type' => 'unit', 'value' => '2rem'],
            'expected_hash' => $read['hashes']['variables'],
        ]);
        $this->assertIsArray($variable, is_wp_error($variable) ? $variable->get_error_message() : '');

        $selector = (new Update_Builder_Content())->handle([
            'builder' => 'oxygen', 'scope' => 'design_system', 'operation' => 'add', 'to' => 'classes',
            'element' => ['name' => 'live-card', 'properties' => ['breakpoint_base' => ['typography' => ['color' => '{var-live-var-1}']]]],
            'expected_hash' => $read['hashes']['classes'],
        ]);
        $this->assertIsArray($selector, is_wp_error($selector) ? $selector->get_error_message() : '');

        $names = array_column(\Breakdance\Variables\getVariables(), 'cssVariableName');
        $this->assertContains('live-gap', $names);

        $selectors = \Breakdance\Data\get_global_option('oxy_selectors_json_string');
        $this->assertIsArray($selectors, 'Oxygen decodes the selectors list in one step');
        $this->assertSame('live-card', $selectors[0]['name']);

        $css = \Breakdance\BreakdanceOxygen\Selectors\cssForSelectors($selectors);
        $this->assertStringContainsString('.live-card', $css);
        $this->assertStringContainsString('var(--live-brand)', $css);

        Rollback_Service::restore_operation($selector['operation_id']);
        Rollback_Service::restore_operation($variable['operation_id']);
        $this->assertSame($raw_selectors, get_option('oxygen_oxy_selectors_json_string'));
        $this->assertSame($raw_variables, get_option('oxygen_variables_json_string'));
    }

    /** A Component whose Text node 101 declares the editable property "heading". */
    private const COMPONENT_WITH_PROPERTY = '{"root":{"id":1,"data":{"type":"root","properties":{}},"children":['
        . '{"id":100,"data":{"type":"OxygenElements\\\\Container","properties":null},"children":['
        . '{"id":101,"data":{"type":"OxygenElements\\\\Text","properties":{"content":{"content":{"text":"Component default"}},'
        . '"meta":{"component":{"editableProperties":[{"enabled":true,"label":"Heading","controlPath":"content.content.text","propertyKey":"heading"}]}}}},"children":[],"_parentId":100}'
        . '],"_parentId":1}]},"_nextNodeId":102,"status":"exported"}';

    private function instance_page(int $component): int
    {
        return $this->oxygen_post('page', '{"root":{"id":1,"data":{"type":"root","properties":{}},"children":['
            . '{"id":100,"data":{"type":"OxygenElements\\\\Component","properties":{"content":{"content":{"block":{"componentId":' . $component . ',"targets":[],"properties":{}}}}}},"children":[],"_parentId":1}'
            . ']},"_nextNodeId":101,"status":"exported"}');
    }

    /**
     * Issue #805: a property value set through wpmcp is what Oxygen's
     * Component renderer puts on the Component's node, and rollback restores
     * the row Oxygen wrote.
     */
    public function test_component_property_values_render_through_oxygen_and_roll_back(): void
    {
        $component = $this->oxygen_post(BREAKDANCE_BLOCK_POST_TYPE, self::COMPONENT_WITH_PROPERTY);
        $page      = $this->instance_page($component);
        $raw       = $this->raw($page, '_oxygen_data');
        $this->assertStringContainsString('Component default', (string) \Breakdance\Render\render($page));

        $declared = (new Get_Builder_Content())->handle(['builder' => 'oxygen', 'scope' => 'properties', 'post_id' => $component]);
        $this->assertSame('heading', $declared['properties'][0]['key']);

        $out  = $this->update($page, ['operation' => 'set_properties', 'path' => '100', 'attrs' => ['heading' => 'Set through wpmcp']]);
        $html = (string) \Breakdance\Render\render($page);
        $this->assertStringContainsString('Set through wpmcp', $html);
        $this->assertStringNotContainsString('Component default', $html);
        $this->assertSame([['nodeId' => 101, 'controlPath' => 'content.content.text', 'propertyKey' => 'heading']], $this->loaded($page)['root']['children'][0]['data']['properties']['content']['content']['block']['targets']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($raw, $this->raw($page, '_oxygen_data'));
    }

    /** Issue #805: create_page makes a page Oxygen loads and renders; rollback trashes it. */
    public function test_create_page_is_a_page_oxygen_loads_and_renders(): void
    {
        $out = (new Update_Builder_Content())->handle([
            'builder' => 'oxygen', 'operation' => 'create_page', 'element' => ['title' => 'Live landing', 'status' => 'publish'],
            'content' => '[{"type":"OxygenElements\\\\Text","properties":{"content":{"content":{"text":"Created through wpmcp"}}}}]',
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        $this->assertSame('oxygen', (new Detect_Builder())->handle(['post_id' => $out['post_id']])['builder']);
        $this->assertSame('Created through wpmcp', $this->loaded($out['post_id'])['root']['children'][0]['data']['properties']['content']['content']['text']);
        $this->assertStringContainsString('Created through wpmcp', (string) \Breakdance\Render\render($out['post_id']));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame('trash', get_post_status($out['post_id']));
    }

    /**
     * Issue #805: a header saved with conditions is one Oxygen's own
     * settings reader and template matcher understand, its conditions are
     * changed against their hash, and rollback undoes both.
     */
    public function test_a_header_with_conditions_is_matched_by_oxygen_and_rolls_back(): void
    {
        $page = $this->oxygen_post();
        $read = (new Get_Builder_Content())->handle(['builder' => 'oxygen', 'scope' => 'conditions']);
        $this->assertIsArray($read, is_wp_error($read) ? $read->get_error_message() : '');
        $this->assertContains('everywhere', array_column($read['template_types'], 'slug'));
        $this->assertContains('user-logged-in-status', array_column($read['conditions'], 'slug'));
        $this->assertNotContains('custom-php', array_column($read['conditions'], 'slug'));

        $settings = ['type' => 'everywhere', 'ruleGroups' => [[['ruleSlug' => 'user-logged-in-status', 'operand' => 'is', 'value' => 'logged in']]], 'priority' => 5];
        $saved    = $this->update($page, ['operation' => 'save_template', 'path' => '100', 'element' => ['title' => 'Live header', 'kind' => 'header', 'settings' => $settings]]);
        $header   = (int) $saved['template_id'];
        $this->assertSame(BREAKDANCE_HEADER_POST_TYPE, get_post_type($header));
        $this->assertSame('publish', get_post_status($header));
        $this->assertSame($settings, \Breakdance\Themeless\getTemplateSettingsFromDatabase($header));
        $this->assertTrue(\Breakdance\Themeless\doesTemplateApply(\Breakdance\Themeless\postToTemplate(get_post($header))), 'Oxygen applies the header to a logged-in request.');
        $this->assertStringContainsString('Saved by Oxygen', (string) \Breakdance\Render\render($header));

        $raw     = $this->raw($header, '_oxygen_template_settings');
        $current = (new Get_Builder_Content())->handle(['builder' => 'oxygen', 'scope' => 'conditions', 'post_id' => $header]);
        $changed = (new Update_Builder_Content())->handle([
            'builder' => 'oxygen', 'post_id' => $header, 'operation' => 'set_conditions', 'expected_hash' => $current['hash'],
            'element' => ['settings' => ['type' => 'everywhere', 'ruleGroups' => [[['ruleSlug' => 'user-logged-in-status', 'operand' => 'is', 'value' => 'logged out']]]]],
        ]);
        $this->assertIsArray($changed, is_wp_error($changed) ? $changed->get_error_message() : '');
        $this->assertFalse(\Breakdance\Themeless\doesTemplateApply(\Breakdance\Themeless\postToTemplate(get_post($header))), 'Oxygen no longer applies it to a logged-in request.');

        Rollback_Service::restore_operation($changed['operation_id']);
        $this->assertSame($raw, $this->raw($header, '_oxygen_template_settings'));
        Rollback_Service::restore_operation($saved['operation_id']);
        $this->assertSame('trash', get_post_status($header));
    }

    /**
     * Issue #805: a page exported with its Component and imported as a new
     * page is one Oxygen loads and renders with the Component in it;
     * rollback trashes the import.
     */
    public function test_an_exported_page_imports_as_a_page_oxygen_renders(): void
    {
        $component = $this->oxygen_post(BREAKDANCE_BLOCK_POST_TYPE, self::COMPONENT_WITH_PROPERTY);
        $page      = $this->instance_page($component);

        $export = (new Get_Builder_Content())->handle(['builder' => 'oxygen', 'scope' => 'export', 'post_id' => $page]);
        $this->assertIsArray($export, is_wp_error($export) ? $export->get_error_message() : '');
        $this->assertSame([$component], array_column($export['package']['components'], 'id'));
        $this->assertSame(\Breakdance\Data\get_meta($page, '_oxygen_data'), $export['package']['item']['breakdanceData'], 'The item carries the row as Oxygen\'s get_meta() reads it.');

        $out = (new Update_Builder_Content())->handle(['builder' => 'oxygen', 'operation' => 'import', 'content' => (string) wp_json_encode($export['package'])]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame($component, $out['components'][ $component ], 'The identical Component on this site is reused.');
        $this->assertSame($this->raw($page, '_oxygen_data'), $this->raw($out['post_id'], '_oxygen_data'));
        $this->assertStringContainsString('Component default', (string) \Breakdance\Render\render($out['post_id']));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame('trash', get_post_status($out['post_id']));
        $this->assertSame('publish', get_post_status($component));
    }
}
