<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Blocksy Companion Pro content blocks (issue #650) as the layout ops of the
 * Blocksy family, built by Theme_Layouts_Pack.
 *
 * Storage: a content block is a ct_content_block post. Its content is the
 * post content; everything else is one blocksy_post_meta_options array,
 * the meta the Blocksy options framework saves for every post and
 * blocksy_get_post_options() reads:
 *
 *  - template_type: hook, popup, header, footer, single, archive,
 *    nothing_found, 404 or maintenance (a block without one is a hook);
 *  - location: the action a hook block prints on, or custom_hook with the
 *    action in custom_location; priority: its priority;
 *  - conditions: Blocksy's ConditionsManager rule list, each row
 *    {type: include|exclude, rule: <rule id>, payload?: {...}};
 *  - has_inline_code: yes for a block whose content is code.
 *
 * Writes only replace the keys these ops own, so every other option (the
 * visibility, expiry and popup settings the dashboard writes) is kept. The
 * ops take rules as {include: [rows], exclude: [rows]} and store them in
 * Blocksy's single conditions list. The hook catalog is the actions the
 * Blocksy theme itself fires, plus the core page hooks.
 */
final class Theme_Layouts_Blocksy extends Theme_Layouts_Adapter
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const META = 'blocksy_post_meta_options';

    private const CUSTOM = 'custom_hook';

    /** Rows one rules key may hold. */
    private const MAX_RULES = 50;

    /** @var array<string,array<int,array{name:string,label:string}>> */
    private const HOOKS = [
        'Page'    => [
            [ 'name' => 'wp_head', 'label' => 'Inside head' ],
            [ 'name' => 'wp_body_open', 'label' => 'After body open' ],
            [ 'name' => 'wp_footer', 'label' => 'Before body close' ],
        ],
        'Header'  => [
            [ 'name' => 'blocksy:header:before', 'label' => 'Before header' ],
            [ 'name' => 'blocksy:header:after', 'label' => 'After header' ],
        ],
        'Content' => [
            [ 'name' => 'blocksy:content:before', 'label' => 'Before content' ],
            [ 'name' => 'blocksy:content:top', 'label' => 'Top of content' ],
            [ 'name' => 'blocksy:content:bottom', 'label' => 'Bottom of content' ],
            [ 'name' => 'blocksy:content:after', 'label' => 'After content' ],
            [ 'name' => 'blocksy:hero:before', 'label' => 'Before page title' ],
            [ 'name' => 'blocksy:hero:after', 'label' => 'After page title' ],
        ],
        'Single'  => [
            [ 'name' => 'blocksy:single:top', 'label' => 'Single top' ],
            [ 'name' => 'blocksy:single:content:top', 'label' => 'Single content top' ],
            [ 'name' => 'blocksy:single:content:bottom', 'label' => 'Single content bottom' ],
            [ 'name' => 'blocksy:single:bottom', 'label' => 'Single bottom' ],
            [ 'name' => 'blocksy:comments:before', 'label' => 'Before comments' ],
            [ 'name' => 'blocksy:comments:after', 'label' => 'After comments' ],
        ],
        'Archive' => [
            [ 'name' => 'blocksy:archive:top', 'label' => 'Archive top' ],
            [ 'name' => 'blocksy:archive:bottom', 'label' => 'Archive bottom' ],
            [ 'name' => 'blocksy:loop:before', 'label' => 'Before posts loop' ],
            [ 'name' => 'blocksy:loop:after', 'label' => 'After posts loop' ],
            [ 'name' => 'blocksy:loop:card:start', 'label' => 'Card start' ],
            [ 'name' => 'blocksy:loop:card:end', 'label' => 'Card end' ],
        ],
        'Footer'  => [
            [ 'name' => 'blocksy:footer:before', 'label' => 'Before footer' ],
            [ 'name' => 'blocksy:footer:after', 'label' => 'After footer' ],
        ],
    ];

    /** The ConditionsManager rule ids of the free companion's basic, pages, posts and user rules. */
    private const BASIC_RULES = [
        'everywhere', 'singulars', 'archives', 'all_post_archives', 'front_page', 'blog', 'search', '404',
        'single_post', 'single_page', 'post_categories', 'post_tags', 'author', 'privacy_policy_page',
        'user_logged_in', 'user_logged_out',
    ];

    public function family(): string
    {
        return 'blocksy';
    }

    public function product(): string
    {
        return 'Blocksy Companion Pro';
    }

    public function plugin_file(): string
    {
        return 'blocksy-companion-pro/blocksy-companion.php';
    }

    protected function loaded(): bool
    {
        return defined('BLOCKSY__FILE__');
    }

    public function post_type(): string
    {
        return 'ct_content_block';
    }

    public function module_hint(): string
    {
        return 'Content Blocks come with Blocksy Companion Pro; activate the Pro companion and its licence under Blocksy > Dashboard.';
    }

    public function types(): array
    {
        return [
            'hook'          => [ 'label' => 'Hook', 'writable' => true, 'hook' => true ],
            'nothing_found' => [ 'label' => 'Nothing Found', 'writable' => true, 'hook' => false ],
            '404'           => [ 'label' => '404 Page', 'writable' => true, 'hook' => false ],
            'popup'         => [ 'label' => 'Popup', 'writable' => false, 'hook' => false ],
            'header'        => [ 'label' => 'Header', 'writable' => false, 'hook' => false ],
            'footer'        => [ 'label' => 'Footer', 'writable' => false, 'hook' => false ],
            'single'        => [ 'label' => 'Single', 'writable' => false, 'hook' => false ],
            'archive'       => [ 'label' => 'Archive', 'writable' => false, 'hook' => false ],
            'maintenance'   => [ 'label' => 'Maintenance', 'writable' => false, 'hook' => false ],
        ];
    }

    public function type_of(int $id): string
    {
        $type = $this->options($id)['template_type'] ?? 'hook';
        return is_string($type) && '' !== $type ? $type : 'hook';
    }

    protected function custom_marker(): string
    {
        return self::CUSTOM;
    }

    protected function hook_catalog(string $type): array
    {
        if ('hook' !== $type) {
            return [];
        }
        $out = [];
        foreach (self::HOOKS as $group => $hooks) {
            $out[] = [ 'group' => $group, 'hooks' => $hooks ];
        }
        return $out;
    }

    public function placement(int $id): array
    {
        if ('hook' !== $this->type_of($id)) {
            return [ 'hook' => null, 'custom_hook' => false, 'priority' => null ];
        }
        $options  = $this->options($id);
        $location = (string) ($options['location'] ?? '');
        $custom   = self::CUSTOM === $location;
        $hook     = $custom ? (string) ($options['custom_location'] ?? '') : $location;
        $priority = $options['priority'] ?? null;

        return [
            'hook'        => '' === $hook ? null : $hook,
            'custom_hook' => $custom,
            'priority'    => is_numeric($priority) ? (int) $priority : null,
        ];
    }

    public function write_new(int $id, string $type): void
    {
        $this->merge($id, [ 'template_type' => $type ]);
    }

    public function write_placement(int $id, string $type, string $hook, bool $custom, int $priority): void
    {
        $this->merge($id, [
            'location'        => $custom ? self::CUSTOM : $hook,
            'custom_location' => $custom ? $hook : '',
            'priority'        => $priority,
        ]);
    }

    public function content_key(string $type, int $id = 0): string
    {
        if ($id > 0 && null !== $this->runs_code($id)) {
            throw new Operation_Refused('code_layout', 'This Blocksy content block holds code; its code is edited in wp-admin only.');
        }
        return 'post_content';
    }

    public function runs_code(int $id): ?string
    {
        return 'yes' === ($this->options($id)['has_inline_code'] ?? '') ? 'php' : null;
    }

    public function location_keys(): array
    {
        return [ 'include', 'exclude' ];
    }

    public function read_rules(int $id): array
    {
        $rows = $this->options($id)['conditions'] ?? [];
        $out  = [ 'include' => [], 'exclude' => [] ];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! isset($row['rule'])) {
                continue;
            }
            $kind = 'exclude' === ($row['type'] ?? 'include') ? 'exclude' : 'include';
            unset($row['type']);
            $out[ $kind ][] = $row;
        }
        return $out;
    }

    public function normalize_rules(array $rules): array
    {
        $out = [];
        foreach ($rules as $key => $value) {
            if ('include' !== $key && 'exclude' !== $key) {
                throw self::bad_rules(sprintf('Blocksy display rules take include and exclude, not "%s".', (string) $key));
            }
            $rows = self::list_of($value, (string) $key);
            if (count($rows) > self::MAX_RULES) {
                throw self::bad_rules(sprintf('Blocksy display rules key "%s" takes at most %d rules.', (string) $key, self::MAX_RULES));
            }
            $out[ $key ] = array_map(fn ($row): array => $this->rule_row($row), $rows);
        }
        return $out;
    }

    public function write_rules(int $id, string $type, array $rules): void
    {
        $current    = $this->read_rules($id);
        $merged     = array_merge($current, $rules);
        $conditions = [];
        foreach ([ 'include', 'exclude' ] as $kind) {
            foreach ($merged[ $kind ] as $row) {
                $conditions[] = [ 'type' => $kind ] + $row;
            }
        }
        $this->merge($id, [ 'conditions' => $conditions ]);
    }

    public function rule_vocabulary(): array
    {
        return [
            'format' => '{include: [{rule, payload?}], exclude: [{rule, payload?}]}, stored as Blocksy\'s conditions list. A rule is a Blocksy display condition id; payload carries the ids a specific rule needs (for example {post_id: 12}).',
            'rules'  => self::BASIC_RULES,
            'note'   => 'Rule ids of the form post_type_<cpt>, user_role_<role> and the specific-post rules the Blocksy dashboard offers are accepted too.',
        ];
    }

    /** @return array<string,mixed> */
    public function extras(int $id): array
    {
        return [ 'template_type' => $this->type_of($id) ];
    }

    /**
     * One validated condition row, without its include/exclude type.
     *
     * @param mixed $row
     * @return array<string,mixed>
     */
    private function rule_row($row): array
    {
        if (! is_array($row) || ! isset($row['rule']) || ! is_string($row['rule'])) {
            throw self::bad_rules('Each Blocksy display rule is an object with a rule id.', $row);
        }
        $rule = $row['rule'];
        if (1 !== preg_match('/^[a-z0-9_-]{1,80}$/', $rule)) {
            throw self::bad_rules(sprintf('"%s" is not a Blocksy display condition id.', $rule), $rule);
        }
        $extra = array_diff(array_keys($row), [ 'rule', 'payload' ]);
        if ([] !== $extra) {
            throw self::bad_rules(sprintf('A Blocksy display rule takes rule and payload, not "%s".', (string) reset($extra)));
        }
        $out = [ 'rule' => $rule ];
        if (array_key_exists('payload', $row)) {
            if (! is_array($row['payload'])) {
                throw self::bad_rules('A Blocksy display rule payload is an object.', $row['payload']);
            }
            $out['payload'] = $row['payload'];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function options(int $id): array
    {
        $value = get_post_meta($id, self::META, true);
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $values written over the stored options, every other key kept */
    private function merge(int $id, array $values): void
    {
        update_post_meta($id, self::META, array_merge($this->options($id), $values));
    }
}
