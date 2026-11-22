<?php

declare(strict_types=1);

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Get_Builder_Content, Oxygen_Page, Update_Builder_Content};

/**
 * Issue #805: saving a page or node as an Oxygen 6 template, header or
 * footer with display conditions, and changing those conditions. Oxygen keeps
 * them in `_oxygen_template_settings` as its saveTemplate() stores them: the
 * settings JSON string, JSON-encoded once more by its set_meta(). They are
 * {type, ruleGroups: [[{ruleSlug, operand, value?, ruleDynamic?}]],
 * priority?, parentId?, disabled?}, checked against the template types and
 * conditions Oxygen registers (stood in for here by wpmcp_oxygen_vocabulary,
 * in the shape of Oxygen's ThemelessController registry).
 */
final class OxygenTemplateConditionsTest extends \WP_UnitTestCase
{
    private const TYPES = ['oxygen_template', 'oxygen_header', 'oxygen_footer', 'oxygen_block'];

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $GLOBALS['wpmcp_breakdance_cache_calls'] = [];
        add_filter('wpmcp_oxygen_active', '__return_true');
        add_filter('wpmcp_oxygen_vocabulary', [self::class, 'vocabulary']);
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
        parent::tearDown();
    }

    /** The registry the way Oxygen's ThemelessController holds it. */
    public static function vocabulary(): array
    {
        return [
            'types'      => [
                'Everywhere' => ['label' => 'Everywhere', 'types' => [['slug' => 'everywhere', 'label' => 'Everywhere']]],
                'Single'     => ['label' => 'Single', 'types' => [['slug' => 'all-singles', 'label' => 'All Singles'], ['slug' => 'page', 'label' => 'Pages']]],
            ],
            'conditions' => [
                ['slug' => 'post-dropdown-page', 'label' => 'Page', 'category' => 'Singular', 'operands' => ['is', 'is not'], 'availableForType' => ['page', 'all-singles', 'oxygen_header'], 'supports' => ['element_display', 'templating']],
                ['slug' => 'user-logged-in', 'label' => 'User Logged In', 'category' => 'User', 'operands' => ['is'], 'availableForType' => ['ALL'], 'supports' => ['element_display', 'templating']],
                ['slug' => 'custom-php', 'label' => 'Custom PHP', 'category' => 'Other', 'operands' => [], 'availableForType' => ['ALL'], 'supports' => ['element_display', 'templating']],
                ['slug' => 'element-only', 'label' => 'Element only', 'category' => 'Other', 'operands' => ['is'], 'availableForType' => ['ALL'], 'supports' => ['element_display']],
                ['slug' => 'post-title', 'label' => 'Title', 'category' => 'Post', 'operands' => ['contains', 'is empty'], 'availableForType' => ['ALL'], 'supports' => ['templating']],
            ],
        ];
    }

    private function raw_meta(int $post_id, string $key, string $value): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value]);
        wp_cache_delete($post_id, 'post_meta');
    }

    private function raw(int $post_id, string $key): ?string
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key));
    }

    private function page(): int
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->raw_meta($id, '_oxygen_data', OxygenAdapterTest::fixture_meta());

        return $id;
    }

    /** A template post as Oxygen's saveTemplate() leaves it. */
    private function template(string $type, array $settings): int
    {
        $id = self::factory()->post->create(['post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Stored ' . $type]);
        $this->raw_meta($id, '_oxygen_data', OxygenAdapterTest::fixture_meta());
        $this->raw_meta($id, '_oxygen_template_settings', (string) wp_json_encode((string) wp_json_encode($settings)));

        return $id;
    }

    private function state(int $post_id): array
    {
        $post = get_post($post_id);
        return [$post->post_content, $post->post_status, get_post_meta($post_id)];
    }

    private function update(int $post_id, array $args)
    {
        return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'oxygen'] + $args);
    }

    private function save(int $page, array $element, string $path = '')
    {
        return $this->update($page, ['operation' => 'save_template', 'path' => $path, 'element' => $element]);
    }

    private function conditions(int $post_id = 0)
    {
        return (new Get_Builder_Content())->handle(['builder' => 'oxygen', 'scope' => 'conditions'] + ($post_id > 0 ? ['post_id' => $post_id] : []));
    }

    // ------------------------------------------------------------------ read

    public function test_conditions_scope_lists_oxygens_template_types_and_templating_conditions(): void
    {
        $out = $this->conditions();

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame(['everywhere', 'all-singles', 'page'], array_column($out['template_types'], 'slug'));
        $this->assertSame('Single', $out['template_types'][2]['category']);
        $slugs = array_column($out['conditions'], 'slug');
        $this->assertSame(['post-dropdown-page', 'user-logged-in', 'post-title'], $slugs, 'Element-only and Custom PHP conditions are not offered.');
        $this->assertSame(['is', 'is not'], $out['conditions'][0]['operands']);
        $this->assertSame(['page', 'all-singles', 'oxygen_header'], $out['conditions'][0]['available_for']);
    }

    public function test_conditions_scope_with_a_template_returns_its_settings_and_hash(): void
    {
        $settings = ['type' => 'everywhere', 'ruleGroups' => [], 'priority' => 1];
        $id       = $this->template('oxygen_header', $settings);

        $out = $this->conditions($id);

        $this->assertSame($id, $out['template_id']);
        $this->assertSame('header', $out['kind']);
        $this->assertSame($settings, $out['settings']);
        $this->assertSame(hash('sha256', (string) $this->raw($id, '_oxygen_template_settings')), $out['hash']);
    }

    public function test_conditions_scope_without_oxygens_registry_says_so(): void
    {
        remove_all_filters('wpmcp_oxygen_vocabulary');

        $out = $this->conditions();

        $this->assertWPError($out);
        $this->assertSame('builder_not_loaded', $out->get_error_code());
    }

    // ---------------------------------------------------------- save_template

    public function test_save_a_node_as_a_header_with_conditions_and_rollback_trashes_it(): void
    {
        $page     = $this->page();
        $before   = $this->state($page);
        $settings = [
            'type'       => 'everywhere',
            'ruleGroups' => [[['ruleSlug' => 'user-logged-in', 'operand' => 'is', 'value' => 'yes']], [['ruleSlug' => 'post-dropdown-page', 'operand' => 'is not', 'value' => ['12', ['text' => 'About', 'value' => '13']]]]],
            'priority'   => 5,
        ];

        $out = $this->save($page, ['title' => 'Site header', 'kind' => 'header', 'settings' => $settings], '1');

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('header', $out['type']);
        $header = get_post($out['template_id']);
        $this->assertSame('oxygen_header', $header->post_type);
        $this->assertSame('publish', $header->post_status);
        $this->assertSame('Site header', $header->post_title);
        $this->assertSame((string) wp_json_encode((string) wp_json_encode($settings)), $this->raw($header->ID, '_oxygen_template_settings'), 'Stored as Oxygen\'s saveTemplate() stores it.');
        $this->assertSame(hash('sha256', (string) $this->raw($header->ID, '_oxygen_template_settings')), $out['settings_hash']);
        $doc = json_decode((string) json_decode((string) $this->raw($header->ID, '_oxygen_data'))->tree_json_string);
        $this->assertSame('OxygenElements\\Container', $doc->root->children[0]->data->type);
        $this->assertSame(Oxygen_Page::hash($header->ID), $out['hash']);
        $this->assertSame($before, $this->state($page), 'The page is left as it is.');

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame('trash', get_post_status($header->ID));
    }

    public function test_save_the_whole_page_as_a_template_that_inherits_from_another(): void
    {
        $page   = $this->page();
        $parent = $this->template('oxygen_template', ['type' => 'all-singles', 'ruleGroups' => []]);

        $out = $this->save($page, ['title' => 'Pages', 'kind' => 'template', 'settings' => ['type' => 'page', 'ruleGroups' => [], 'parentId' => $parent]]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('oxygen_template', get_post_type($out['template_id']));
        $outer = json_decode((string) $this->raw($out['template_id'], '_oxygen_data'));
        $this->assertSame(OxygenAdapterTest::fixture_json(), $outer->tree_json_string);
        $stored = json_decode((string) json_decode((string) $this->raw($out['template_id'], '_oxygen_template_settings')), true);
        $this->assertSame(['type' => 'page', 'ruleGroups' => [], 'parentId' => $parent], $stored);
    }

    public function test_conditions_outside_oxygens_vocabulary_are_refused_and_nothing_is_created(): void
    {
        $page   = $this->page();
        $footer = $this->template('oxygen_footer', ['type' => 'everywhere', 'ruleGroups' => []]);
        $rule   = static fn (array $r): array => ['type' => 'everywhere', 'ruleGroups' => [[$r]]];
        $cases  = [
            'unknown type'       => [['type' => 'nowhere', 'ruleGroups' => []], 'nowhere'],
            'no type'            => [['ruleGroups' => []], 'type'],
            'unknown condition'  => [$rule(['ruleSlug' => 'moon-phase', 'operand' => 'is', 'value' => 'full']), 'moon-phase'],
            'bad operand'        => [$rule(['ruleSlug' => 'user-logged-in', 'operand' => 'is not', 'value' => 'yes']), "'is'"],
            'not for this type'  => [$rule(['ruleSlug' => 'post-dropdown-page', 'operand' => 'is', 'value' => '1']), 'everywhere'],
            'element only'       => [$rule(['ruleSlug' => 'element-only', 'operand' => 'is', 'value' => 'x']), 'element-only'],
            'custom php'         => [$rule(['ruleSlug' => 'custom-php', 'operand' => '', 'value' => 'return true;']), 'PHP'],
            'missing value'      => [$rule(['ruleSlug' => 'user-logged-in', 'operand' => 'is']), 'value'],
            'bad value'          => [$rule(['ruleSlug' => 'user-logged-in', 'operand' => 'is', 'value' => ['x' => 1]]), 'value'],
            'unknown rule key'   => [$rule(['ruleSlug' => 'user-logged-in', 'operand' => 'is', 'value' => 'yes', 'eval' => 'x']), 'eval'],
            'unknown key'        => [['type' => 'everywhere', 'ruleGroups' => [], 'fallback' => true], 'fallback'],
            'groups not lists'   => [['type' => 'everywhere', 'ruleGroups' => ['x']], 'ruleGroups'],
            'empty group'        => [['type' => 'everywhere', 'ruleGroups' => [[]]], 'ruleGroups'],
            'bad priority'       => [['type' => 'everywhere', 'ruleGroups' => [], 'priority' => 'high'], 'priority'],
            'parent not a tmpl'  => [['type' => 'page', 'ruleGroups' => [], 'parentId' => $footer], 'parentId'],
        ];
        foreach ($cases as $label => [$settings, $needle]) {
            $out = $this->save($page, ['title' => 'X', 'kind' => 'template', 'settings' => $settings]);
            $this->assertWPError($out, $label);
            $this->assertSame('invalid_oxygen_request', $out->get_error_code(), $label);
            $this->assertStringContainsString($needle, $out->get_error_message(), $label);
        }

        // An empty-value operand needs no value.
        $ok = $this->save($page, ['title' => 'Untitled posts', 'kind' => 'template', 'settings' => $rule(['ruleSlug' => 'post-title', 'operand' => 'is empty'])]);
        $this->assertIsArray($ok, is_wp_error($ok) ? $ok->get_error_message() : '');

        foreach ([['title' => 'X', 'kind' => 'popup', 'settings' => ['type' => 'everywhere']], ['kind' => 'header', 'settings' => ['type' => 'everywhere']], ['title' => 'X', 'kind' => 'header']] as $element) {
            $out = $this->save($page, $element);
            $this->assertSame('invalid_oxygen_request', $out->get_error_code(), (string) wp_json_encode($element));
        }
        $this->assertCount(1, get_posts(['post_type' => 'oxygen_template', 'post_status' => 'any']));
        $this->assertCount(0, get_posts(['post_type' => 'oxygen_header', 'post_status' => 'any']));
    }

    public function test_a_header_may_use_any_templating_condition_with_the_everywhere_type(): void
    {
        $out = $this->save($this->page(), ['title' => 'Logged-in header', 'kind' => 'footer', 'settings' => ['type' => 'everywhere', 'ruleGroups' => [[['ruleSlug' => 'post-dropdown-page', 'operand' => 'is', 'value' => '2']]]]]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('oxygen_footer', get_post_type($out['template_id']));
    }

    public function test_save_template_kinds_need_oxygen_its_post_type_and_publish_rights(): void
    {
        $page     = $this->page();
        $element  = ['title' => 'H', 'kind' => 'header', 'settings' => ['type' => 'everywhere', 'ruleGroups' => []]];

        unregister_post_type('oxygen_header');
        $this->assertSame('builder_not_loaded', $this->save($page, $element)->get_error_code());
        register_post_type('oxygen_header', ['public' => false, 'capability_type' => 'post']);

        $contributor = self::factory()->user->create(['role' => 'contributor']);
        wp_set_current_user($contributor);
        $own = self::factory()->post->create(['post_type' => 'post', 'post_status' => 'draft', 'post_author' => $contributor]);
        $this->raw_meta($own, '_oxygen_data', OxygenAdapterTest::fixture_meta());
        $this->assertSame('forbidden', $this->save($own, $element)->get_error_code());

        remove_all_filters('wpmcp_oxygen_active');
        add_filter('wpmcp_oxygen_active', '__return_false');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertSame('plugin_missing', $this->save($page, $element)->get_error_code());
        $this->assertCount(0, get_posts(['post_type' => 'oxygen_header', 'post_status' => 'any']));
    }

    // --------------------------------------------------------- set_conditions

    public function test_set_conditions_replaces_the_settings_against_their_hash_and_rolls_back(): void
    {
        $id     = $this->template('oxygen_header', ['type' => 'everywhere', 'ruleGroups' => [], 'priority' => 1]);
        $before = $this->state($id);
        $raw    = $this->raw($id, '_oxygen_template_settings');
        $new    = ['type' => 'everywhere', 'ruleGroups' => [[['ruleSlug' => 'user-logged-in', 'operand' => 'is', 'value' => 'yes']]], 'disabled' => true];
        $args   = ['operation' => 'set_conditions', 'element' => ['settings' => $new]];

        $this->assertSame('missing_expected_hash', $this->update($id, $args)->get_error_code());
        $this->assertSame('stale_expected_hash', $this->update($id, $args + ['expected_hash' => hash('sha256', 'x')])->get_error_code());
        $this->assertSame($before, $this->state($id));

        $out = $this->update($id, $args + ['expected_hash' => $this->conditions($id)['hash']]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame($new, $out['settings']);
        $this->assertSame((string) wp_json_encode((string) wp_json_encode($new)), $this->raw($id, '_oxygen_template_settings'));
        $this->assertSame($this->conditions($id)['hash'], $out['hash']);
        $this->assertSame(OxygenAdapterTest::fixture_meta(), $this->raw($id, '_oxygen_data'), 'The layout is untouched.');

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($raw, $this->raw($id, '_oxygen_template_settings'));
        $this->assertSame($before, $this->state($id));
    }

    public function test_set_conditions_refusals(): void
    {
        $page      = $this->page();
        $component = $this->template('oxygen_block', ['type' => 'everywhere']);
        $fallback  = $this->template('oxygen_template', ['type' => 'all-singles', 'ruleGroups' => [], 'fallback' => true]);
        $template  = $this->template('oxygen_template', ['type' => 'all-singles', 'ruleGroups' => []]);
        $settings  = ['settings' => ['type' => 'all-singles', 'ruleGroups' => []]];

        foreach ([$page, $component] as $id) {
            $out = $this->update($id, ['operation' => 'set_conditions', 'element' => $settings, 'expected_hash' => 'x']);
            $this->assertSame('invalid_oxygen_request', $out->get_error_code());
        }
        $out = $this->update($fallback, ['operation' => 'set_conditions', 'element' => $settings, 'expected_hash' => $this->conditions($fallback)['hash']]);
        $this->assertSame('invalid_oxygen_request', $out->get_error_code());
        $this->assertStringContainsString('fallback', $out->get_error_message());

        $self = $this->update($template, ['operation' => 'set_conditions', 'element' => ['settings' => ['type' => 'page', 'ruleGroups' => [], 'parentId' => $template]], 'expected_hash' => $this->conditions($template)['hash']]);
        $this->assertSame('invalid_oxygen_request', $self->get_error_code());
        $this->assertStringContainsString('parentId', $self->get_error_message());

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $out = $this->update($template, ['operation' => 'set_conditions', 'element' => $settings, 'expected_hash' => $this->conditions($template)['hash']]);
        $this->assertSame('forbidden', $out->get_error_code());
    }
}
