<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * An Oxygen 6 design library export (issue #805). Each item is in the shape
 * Oxygen's design library endpoints serve it:
 *
 * - a page or post: getBreakdancePostData(), {postData: {post_title,
 *   post_name, post_content, post_excerpt, post_status, post_type},
 *   breakdanceData: {tree_json_string}};
 * - a template, header, footer, Component or part: getTemplateData(),
 *   {title, settings, postType, breakdanceData}.
 *
 * `components` lists the Components the item places, in the template shape
 * plus their source `id` (what Oxygen's importer passes as idsToMap), and
 * `variables` / `selectors` are the documents getVariablesForDesignLibrary()
 * and getOxySelectorsForDesignLibrary() return. `wpmcp_oxygen_export` marks
 * the format version and `source_url` the site it came from.
 */
final class Oxygen6_Library_Package
{
    public const FORMAT = 1;

    /**
     * @param array<string,mixed> $item
     * @param array<int,array<string,mixed>> $components
     * @param array<string,mixed> $variables
     * @param array<string,mixed> $selectors
     */
    public function __construct(
        public readonly array $item,
        public readonly array $components,
        public readonly array $variables,
        public readonly array $selectors,
        public readonly string $source_url,
        public readonly ?string $oxygen_version = null
    ) {
    }

    /** Parse and check a package passed as JSON. */
    public static function from_json(string $json): self
    {
        $data = json_decode($json, true);
        if (! is_array($data) || self::FORMAT !== ($data['wpmcp_oxygen_export'] ?? null)) {
            Oxygen6_Request_Error::raise('content must be an Oxygen export as get-builder-content scope export returns its package (wpmcp_oxygen_export 1), JSON-encoded.');
        }
        $item = $data['item'] ?? null;
        if (! is_array($item) || ! (self::is_post_item($item) || self::is_template_item($item))) {
            Oxygen6_Request_Error::raise('The export item must be {postData, breakdanceData} or {title, settings, postType, breakdanceData}, each breakdanceData holding a tree_json_string with an Oxygen tree.');
        }
        $components = $data['components'] ?? [];
        if (! is_array($components) || ! array_is_list($components)) {
            Oxygen6_Request_Error::raise('components must be a list.');
        }
        $checked = [];
        foreach ($components as $component) {
            if (! is_array($component) || ! is_int($component['id'] ?? null) || ! self::is_template_item($component) || Oxygen6_Store::KINDS['block'] !== $component['postType']) {
                Oxygen6_Request_Error::raise('Every entry of components must be {id, title, settings, postType: oxygen_block, breakdanceData}.');
            }
            $checked[] = Oxygen6_Values::map($component);
        }

        return new self(
            Oxygen6_Values::map($item),
            $checked,
            Oxygen6_Values::map($data['variables'] ?? null),
            Oxygen6_Values::map($data['selectors'] ?? null),
            is_string($data['source_url'] ?? null) ? $data['source_url'] : '',
            is_string($data['oxygen_version'] ?? null) ? $data['oxygen_version'] : null
        );
    }

    /** @return array<string,mixed> */
    public function to_array(): array
    {
        return [
            'wpmcp_oxygen_export' => self::FORMAT,
            'source_url'          => $this->source_url,
            'oxygen_version'      => $this->oxygen_version,
            'item'                => $this->item,
            'components'          => $this->components,
            'variables'           => $this->variables,
            'selectors'           => $this->selectors,
        ];
    }

    public function is_template(): bool
    {
        return ! isset($this->item['postData']);
    }

    public function post_type(): string
    {
        return Oxygen6_Values::str($this->is_template() ? Oxygen6_Values::at($this->item, 'postType') : Oxygen6_Values::at($this->item, 'postData', 'post_type'));
    }

    public function title(): string
    {
        return Oxygen6_Values::str($this->is_template() ? Oxygen6_Values::at($this->item, 'title') : Oxygen6_Values::at($this->item, 'postData', 'post_title'));
    }

    public function tree(): string
    {
        return self::tree_of($this->item);
    }

    /**
     * A template item's settings (null when it stores none).
     *
     * @return array<string,mixed>|null
     */
    public function settings(): ?array
    {
        $settings = $this->item['settings'] ?? null;

        return is_array($settings) ? Oxygen6_Values::map($settings) : null;
    }

    /**
     * The tree_json_string of an item or a components entry.
     *
     * @param array<string,mixed> $item
     */
    public static function tree_of(array $item): string
    {
        return Oxygen6_Values::str(Oxygen6_Values::at($item, 'breakdanceData', 'tree_json_string'));
    }

    /** @param array<mixed> $item */
    private static function is_post_item(array $item): bool
    {
        $post = $item['postData'] ?? null;

        return is_array($post) && is_string($post['post_type'] ?? null) && is_string($post['post_title'] ?? null) && self::has_tree($item);
    }

    /** @param array<mixed> $item */
    private static function is_template_item(array $item): bool
    {
        return is_string($item['title'] ?? null) && is_string($item['postType'] ?? null) && array_key_exists('settings', $item)
            && (null === $item['settings'] || is_array($item['settings'])) && self::has_tree($item);
    }

    /** @param array<mixed> $item */
    private static function has_tree(array $item): bool
    {
        $tree = Oxygen6_Values::at($item, 'breakdanceData', 'tree_json_string');

        return is_string($tree) && null !== Breakdance_Tree::decode($tree);
    }
}
