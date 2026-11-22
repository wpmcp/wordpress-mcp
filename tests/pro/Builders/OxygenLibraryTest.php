<?php

declare(strict_types=1);

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Builder_Detector, Get_Builder_Content, Oxygen_Page, Update_Builder_Content};

/**
 * Issue #805: Oxygen 6 create_page and design library export and import.
 *
 * The export carries each item in the shape Oxygen's design library serves
 * it (getBreakdancePostData(): {postData, breakdanceData}; getTemplateData():
 * {title, settings, postType, breakdanceData}), the Components the item uses
 * in the template shape plus their source id, and the variables and
 * selectors documents its getVariablesForDesignLibrary() and
 * getOxySelectorsForDesignLibrary() return. Import creates the post as
 * importPostOrPage() / importTemplateById() do, maps Component ids to the
 * local copies and rewrites the source site's links in templates the way
 * updateDesignLibraryUrls() does. Nothing is fetched from a remote site.
 */
final class OxygenLibraryTest extends \WP_UnitTestCase
{
    private const TYPES = ['oxygen_template', 'oxygen_header', 'oxygen_footer', 'oxygen_block'];

    private const COMPONENT_TREE = '{"root":{"id":1,"data":{"type":"root","properties":{}},"children":['
        . '{"id":100,"data":{"type":"OxygenElements\\\\Text","properties":{"content":{"content":{"text":"Inside the Component"}}}},"children":[],"_parentId":1}'
        . ']},"_nextNodeId":101,"status":"exported"}';

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $GLOBALS['wpmcp_breakdance_cache_calls'] = [];
        add_filter('wpmcp_oxygen_active', '__return_true');
        add_filter('wpmcp_oxygen_vocabulary', [OxygenTemplateConditionsTest::class, 'vocabulary']);
        foreach (self::TYPES as $type) {
            register_post_type($type, ['public' => false, 'capability_type' => 'post']);
        }
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_oxygen_active');
        remove_all_filters('wpmcp_oxygen_vocabulary');
        foreach (self::TYPES as $type) {
            unregister_post_type($type);
        }
        delete_option('oxygen_variables_json_string');
        delete_option('oxygen_oxy_selectors_json_string');
        parent::tearDown();
    }

    private function raw_meta(int $post_id, string $key, string $value): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value]);
        wp_cache_delete($post_id, 'post_meta');
    }

    private function raw(int $post_id, string $key = '_oxygen_data'): ?string
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key));
    }

    private function post(string $type, string $tree, string $title = 'Item', string $status = 'publish'): int
    {
        $id = self::factory()->post->create(['post_type' => $type, 'post_status' => $status, 'post_title' => $title, 'post_content' => '', 'post_excerpt' => '']);
        $this->raw_meta($id, '_oxygen_data', (string) wp_json_encode(['tree_json_string' => $tree]));

        return $id;
    }

    /** A page tree placing Component $component, with a link into the source site. */
    private function page_tree(int $component): string
    {
        return '{"root":{"id":1,"data":{"type":"root","properties":{}},"children":['
            . '{"id":100,"data":{"type":"OxygenElements\\\\Component","properties":{"content":{"content":{"block":{"componentId":' . $component . ',"targets":[],"properties":{}}}}}},"children":[],"_parentId":1},'
            . '{"id":101,"data":{"type":"OxygenElements\\\\TextLink","properties":{"content":{"content":{"text":"About","url":"https://source.example/about/"}}}},"children":[],"_parentId":1}'
            . ']},"_nextNodeId":102,"status":"exported"}';
    }

    private function design(): void
    {
        // As Oxygen's saveVariables() / saveSelectors() store them: the
        // document JSON-encoded once more by its option API.
        update_option('oxygen_variables_json_string', (string) wp_json_encode((string) wp_json_encode(['variables' => [['id' => 'v1', 'cssVariableName' => 'brand', 'type' => 'color', 'label' => 'Brand', 'value' => '#123', 'collection' => 'Default']], 'collections' => ['Default']])));
        update_option('oxygen_oxy_selectors_json_string', (string) wp_json_encode((string) wp_json_encode(['selectors' => [['id' => 's1', 'name' => 'card', 'type' => 'class', 'collection' => 'Default', 'children' => [], 'locked' => false, 'properties' => null]], 'collections' => ['Default']])));
    }

    private function export(int $post_id)
    {
        return (new Get_Builder_Content())->handle(['builder' => 'oxygen', 'scope' => 'export', 'post_id' => $post_id]);
    }

    private function write(array $args)
    {
        return (new Update_Builder_Content())->handle(['builder' => 'oxygen'] + $args);
    }

    private function tree(int $post_id): object
    {
        return json_decode((string) json_decode((string) $this->raw($post_id))->tree_json_string);
    }

    // ----------------------------------------------------------- create_page

    public function test_create_page_makes_a_draft_page_with_a_blank_oxygen_layout_and_rollback_trashes_it(): void
    {
        $out = $this->write(['operation' => 'create_page', 'element' => ['title' => 'Landing']]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $post = get_post($out['post_id']);
        $this->assertSame('page', $post->post_type);
        $this->assertSame('draft', $post->post_status);
        $this->assertSame('Landing', $post->post_title);
        $this->assertSame((string) wp_json_encode(['tree_json_string' => (string) wp_json_encode(Oxygen_Page::blank(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS)]), $this->raw($post->ID));
        $this->assertSame('oxygen', Builder_Detector::detect($post->ID));
        $this->assertSame(Oxygen_Page::hash($post->ID), $out['hash']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame('trash', get_post_status($post->ID));
    }

    public function test_create_page_takes_a_post_type_status_and_starting_nodes(): void
    {
        $out = $this->write(['operation' => 'create_page', 'element' => ['title' => 'News', 'post_type' => 'post', 'status' => 'publish'], 'content' => '[{"type":"OxygenElements\\\\Text","properties":{"content":{"content":{"text":"Hello"}}}}]']);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('publish', get_post_status($out['post_id']));
        $this->assertSame('post', get_post_type($out['post_id']));
        $node = $this->tree($out['post_id'])->root->children[0];
        $this->assertSame('Hello', $node->data->properties->content->content->text);
        $this->assertSame(100, $node->id);
    }

    public function test_create_page_refusals_create_nothing(): void
    {
        $count = static fn (): int => count(get_posts(['post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1]));
        $start = $count();

        foreach ([
            ['element' => []],
            ['element' => ['title' => 'X', 'post_type' => 'oxygen_header']],
            ['element' => ['title' => 'X', 'post_type' => 'nope']],
            ['element' => ['title' => 'X', 'status' => 'future']],
            ['element' => ['title' => 'X'], 'content' => '{"not":"a list"}'],
        ] as $args) {
            $out = $this->write(['operation' => 'create_page'] + $args);
            $this->assertSame('invalid_oxygen_request', $out->get_error_code(), (string) wp_json_encode($args));
        }

        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $this->assertSame('forbidden', $this->write(['operation' => 'create_page', 'element' => ['title' => 'X', 'status' => 'publish']])->get_error_code());

        remove_all_filters('wpmcp_oxygen_active');
        add_filter('wpmcp_oxygen_active', '__return_false');
        $this->assertSame('plugin_missing', $this->write(['operation' => 'create_page', 'element' => ['title' => 'X']])->get_error_code());
        $this->assertSame($start, $count());
    }

    // ---------------------------------------------------------------- export

    public function test_export_a_page_in_oxygens_design_library_shape_with_its_components_and_design_system(): void
    {
        $this->design();
        $component = $this->post('oxygen_block', self::COMPONENT_TREE, 'Card');
        $page      = $this->post('page', $this->page_tree($component), 'Home');

        $out = $this->export($page);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $package = $out['package'];
        $this->assertSame(1, $package['wpmcp_oxygen_export']);
        $this->assertSame(home_url(), $package['source_url']);
        $this->assertSame(['post_title' => 'Home', 'post_name' => get_post($page)->post_name, 'post_content' => '', 'post_excerpt' => '', 'post_status' => 'publish', 'post_type' => 'page'], $package['item']['postData']);
        $this->assertSame(['tree_json_string' => $this->page_tree($component)], $package['item']['breakdanceData']);
        $this->assertSame([['id' => $component, 'title' => 'Card', 'settings' => null, 'postType' => 'oxygen_block', 'breakdanceData' => ['tree_json_string' => self::COMPONENT_TREE]]], $package['components']);
        $this->assertSame('brand', $package['variables']['variables'][0]['cssVariableName']);
        $this->assertSame(['Default'], $package['selectors']['collections']);
        $this->assertSame(Oxygen_Page::hash($page), $out['hash']);
    }

    public function test_export_a_template_carries_its_settings(): void
    {
        $settings = ['type' => 'everywhere', 'ruleGroups' => []];
        $header   = $this->post('oxygen_header', self::COMPONENT_TREE, 'Header');
        $this->raw_meta($header, '_oxygen_template_settings', (string) wp_json_encode((string) wp_json_encode($settings)));

        $item = $this->export($header)['package']['item'];

        $this->assertSame(['title' => 'Header', 'settings' => $settings, 'postType' => 'oxygen_header', 'breakdanceData' => ['tree_json_string' => self::COMPONENT_TREE]], $item);
    }

    public function test_export_refuses_a_post_without_an_oxygen_layout(): void
    {
        $plain = self::factory()->post->create(['post_type' => 'page']);

        $this->assertSame('invalid_oxygen_request', $this->export($plain)->get_error_code());
    }

    // ---------------------------------------------------------------- import

    public function test_import_on_the_same_site_reuses_the_identical_component_and_rollback_trashes_the_page(): void
    {
        $component = $this->post('oxygen_block', self::COMPONENT_TREE, 'Card');
        $page      = $this->post('page', $this->page_tree($component), 'Home');
        $package   = $this->export($page)['package'];

        $out = $this->write(['operation' => 'import', 'content' => (string) wp_json_encode($package)]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertNotSame($page, $out['post_id']);
        $this->assertSame([(string) $component => $component], $out['components']);
        $this->assertSame($this->raw($page), $this->raw($out['post_id']), 'The data row is the one Oxygen\'s import stores.');
        $this->assertSame('Home', get_the_title($out['post_id']));
        $this->assertCount(1, get_posts(['post_type' => 'oxygen_block', 'post_status' => 'any']));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame('trash', get_post_status($out['post_id']));
        $this->assertSame('publish', get_post_status($component));
    }

    public function test_import_from_another_site_creates_its_components_and_maps_their_ids(): void
    {
        $this->design();
        $package = [
            'wpmcp_oxygen_export' => 1,
            'source_url'          => 'https://source.example',
            'item'                => ['title' => 'Imported header', 'settings' => ['type' => 'everywhere', 'ruleGroups' => []], 'postType' => 'oxygen_header', 'breakdanceData' => ['tree_json_string' => $this->page_tree(4242)]],
            'components'          => [['id' => 4242, 'title' => 'Remote card', 'settings' => null, 'postType' => 'oxygen_block', 'breakdanceData' => ['tree_json_string' => self::COMPONENT_TREE]]],
            'variables'           => ['variables' => [['id' => 'v1'], ['id' => 'v-remote']], 'collections' => ['Default']],
            'selectors'           => ['selectors' => [['id' => 's-remote']], 'collections' => ['Default']],
        ];

        $out = $this->write(['operation' => 'import', 'content' => (string) wp_json_encode($package)]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $header = get_post($out['post_id']);
        $this->assertSame('oxygen_header', $header->post_type);
        $this->assertSame('publish', $header->post_status);
        $new = $out['components']['4242'];
        $this->assertSame('oxygen_block', get_post_type($new));
        $this->assertSame('Remote card', get_the_title($new));
        $tree = $this->tree($header->ID);
        $this->assertSame($new, $tree->root->children[0]->data->properties->content->content->block->componentId);
        $this->assertSame(home_url('/about/'), $tree->root->children[1]->data->properties->content->content->url, 'Source links are rewritten as Oxygen\'s template import does.');
        $this->assertSame((string) wp_json_encode((string) wp_json_encode(['type' => 'everywhere', 'ruleGroups' => []])), $this->raw($header->ID, '_oxygen_template_settings'));
        $this->assertSame(['variables' => ['v-remote'], 'selectors' => ['s-remote']], $out['design_missing']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame('trash', get_post_status($header->ID));
        $this->assertSame('trash', get_post_status($new));
    }

    public function test_import_into_a_blank_post_writes_its_layout_and_rolls_back(): void
    {
        $component = $this->post('oxygen_block', self::COMPONENT_TREE, 'Card');
        $package   = $this->export($this->post('page', $this->page_tree($component), 'Home'))['package'];
        $blank     = self::factory()->post->create(['post_type' => 'page', 'post_content' => '', 'post_title' => 'Blank']);
        $before    = get_post_meta($blank);

        $out = $this->write(['operation' => 'import', 'post_id' => $blank, 'content' => (string) wp_json_encode($package)]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame($blank, $out['post_id']);
        $this->assertSame('Blank', get_the_title($blank), 'A blank post keeps its own title.');
        $this->assertSame(Oxygen_Page::hash($blank), $out['hash']);
        $this->assertSame($component, $this->tree($blank)->root->children[0]->data->properties->content->content->block->componentId);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, get_post_meta($blank));
    }

    public function test_import_refusals_write_nothing(): void
    {
        $component = $this->post('oxygen_block', self::COMPONENT_TREE, 'Card');
        $page      = $this->post('page', $this->page_tree($component), 'Home');
        $package   = $this->export($page)['package'];
        $remote    = ['components' => [['id' => 5, 'title' => 'Not here', 'settings' => null, 'postType' => 'oxygen_block', 'breakdanceData' => ['tree_json_string' => self::COMPONENT_TREE]]]] + $package;
        $blank     = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        $posts     = count(get_posts(['post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1]));
        $code      = $package;
        $code['item']['postData']['post_type'] = 'post';
        $code['item']['breakdanceData']['tree_json_string'] = str_replace('OxygenElements\\\\TextLink', 'OxygenElements\\\\PhpCode', $code['item']['breakdanceData']['tree_json_string']);

        $cases = [
            ['content' => 'not json'],
            ['content' => '{"item":{}}'],
            ['content' => (string) wp_json_encode(['item' => ['title' => 'x', 'settings' => ['type' => 'all-singles', 'ruleGroups' => [], 'fallback' => true], 'postType' => 'oxygen_template', 'breakdanceData' => ['tree_json_string' => self::COMPONENT_TREE]]] + $package)],
            ['content' => (string) wp_json_encode(['item' => ['title' => 'x', 'settings' => null, 'postType' => 'oxygen_popup', 'breakdanceData' => ['tree_json_string' => self::COMPONENT_TREE]]] + $package)],
            ['content' => (string) wp_json_encode($package), 'post_id' => $page],
            ['content' => (string) wp_json_encode($remote), 'post_id' => $blank],
        ];
        foreach ($cases as $args) {
            $out = $this->write(['operation' => 'import'] + $args);
            $this->assertWPError($out, substr((string) wp_json_encode($args), 0, 120));
            $this->assertSame('invalid_oxygen_request', $out->get_error_code(), substr((string) wp_json_encode($args), 0, 120));
        }

        // An Author may publish posts but has no unfiltered_html.
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $author = $this->write(['operation' => 'import', 'content' => (string) wp_json_encode($code)]);
        $this->assertSame('forbidden', $author->get_error_code());
        $this->assertStringContainsString('unfiltered_html', $author->get_error_message());

        remove_all_filters('wpmcp_oxygen_active');
        add_filter('wpmcp_oxygen_active', '__return_false');
        $this->assertSame('plugin_missing', $this->write(['operation' => 'import', 'content' => (string) wp_json_encode($package)])->get_error_code());
        $this->assertSame('', (string) $this->raw($blank));
        $this->assertSame($posts, count(get_posts(['post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1])));
    }
}
