<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * update-builder-content operation import (issue #805): put an
 * Oxygen6_Library_Package (content, JSON) on this site the way Oxygen's
 * design library import does (importPostOrPage(), importTemplateById()):
 *
 * - Without post_id the item becomes a new post of its own type: a page or
 *   post with its postData (element {status} overrides the status), a
 *   template, header, footer, Component or part published with its
 *   settings stored as saveTemplate() stores them.
 * - With post_id the layout (and a template's settings) goes into that
 *   post, which must be blank and of the item's type; it keeps its title.
 * - Each Component the package carries is reused when a published one with
 *   the same title and the same data row exists (Oxygen's own "already
 *   exists" check), else created; Component ids in the trees are mapped to
 *   the local ones on the keys mapBlockIdsToLocal() maps.
 * - Links to the source site in templates and Components are rewritten to
 *   this site, as updateDesignLibraryUrls() does (media URLs are left).
 * - Variables and selectors are not overwritten (Oxygen's import replaces
 *   the whole list); the ids this site lacks are returned as
 *   design_missing, to add through scope design_system.
 *
 * Nothing is fetched from another site. A new post and every Component
 * created with it are one creation row (rollback trashes them all); a
 * write into a blank post is its post snapshot, so it creates no
 * Components.
 */
final class Oxygen6_Library_Importer
{
    private const STATUSES = ['draft', 'pending', 'private', 'publish'];

    /** The keys Oxygen's mapBlockIdsToLocal() rewrites. */
    private const ID_KEYS = ['block', 'global_block', 'componentId', 'when_empty', 'after_title_bar', 'after_footer'];

    public function __construct(
        private readonly Oxygen6_Store $store,
        private readonly Oxygen6_Snapshots $snapshots,
        private readonly Oxygen6_Guard $guard,
        private readonly Oxygen6_Vocabulary_Source $vocabulary
    ) {
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public function import(array $args): array|\WP_Error
    {
        $package  = Oxygen6_Library_Package::from_json(Oxygen6_Values::str($args['content'] ?? null));
        $type     = $package->post_type();
        $kind     = $this->store->kind_of($type);
        $settings = $this->settings($package, $kind);
        if (! $package->is_template() && null !== $kind) {
            Oxygen6_Request_Error::raise("A postData item cannot be of Oxygen's template type {$type}.");
        }
        if ($package->is_template() && null === $kind) {
            Oxygen6_Request_Error::raise("Unsupported template type '{$type}'; Oxygen 6 imports " . implode(', ', Oxygen6_Store::KINDS) . '.');
        }
        if (! post_type_exists($type) || (null === $kind && ! is_post_type_viewable($type))) {
            return null !== $kind
                ? new \WP_Error('builder_not_loaded', "Importing needs Oxygen 6 loaded on this site, since the {$type} post type is its own.")
                : new \WP_Error('invalid_oxygen_request', "Unknown or non-public post type '{$type}'.");
        }

        $docs = [Breakdance_Tree::decode($package->tree())];
        foreach ($package->components as $component) {
            $docs[] = Breakdance_Tree::decode(Oxygen6_Library_Package::tree_of($component));
        }
        $refusal = $this->guard->code_refusal(array_filter($docs));
        if (null !== $refusal) {
            return $refusal;
        }

        [$map, $create] = $this->resolve($package);
        $target = Oxygen6_Values::int($args['post_id'] ?? null);

        return $target > 0
            ? $this->into_blank($target, $package, $settings, $map, $create, $args)
            : $this->as_new($package, $settings, $map, $create, $args);
    }

    /**
     * The item's settings: checked against Oxygen's vocabulary for the kinds
     * that carry conditions; a Component's or part's are kept as they are.
     *
     * @return array<string,mixed>|null
     */
    private function settings(Oxygen6_Library_Package $package, ?string $kind): ?array
    {
        if (! $package->is_template()) {
            return null;
        }
        $settings = $package->settings();
        if (true === ($settings['fallback'] ?? false)) {
            Oxygen6_Request_Error::raise("This is one of Oxygen's fallback templates, which Oxygen's import skips.");
        }
        if (null === $settings || ! in_array($kind, Oxygen6_Store::CONDITION_KINDS, true)) {
            return $settings;
        }
        $vocabulary = $this->vocabulary->load();
        if (null === $vocabulary) {
            Oxygen6_Request_Error::raise("Oxygen's template types and conditions are not loaded, so the item's conditions cannot be checked.");
        }

        return (new Oxygen6_Settings_Validator($vocabulary, $this->store))->validate($settings, $kind)->to_array();
    }

    /**
     * Source Component id => local id for the ones that exist here, and the
     * package entries still to create.
     *
     * @return array{0:array<int,int>,1:array<int,array<string,mixed>>}
     */
    private function resolve(Oxygen6_Library_Package $package): array
    {
        $map    = [];
        $create = [];
        foreach ($package->components as $component) {
            $found = null;
            foreach ($this->store->published_with_title(Oxygen6_Store::KINDS['block'], Oxygen6_Values::str($component['title'] ?? null)) as $id) {
                if ($this->store->data_row($id) === $component['breakdanceData']) {
                    $found = $id;
                    break;
                }
            }
            if (null === $found) {
                $create[] = $component;
            } else {
                $map[ Oxygen6_Values::int($component['id'] ?? null) ] = $found;
            }
        }

        return [$map, $create];
    }

    /**
     * @param array<string,mixed>|null $settings
     * @param array<int,int> $map
     * @param array<int,array<string,mixed>> $create
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    private function into_blank(int $post_id, Oxygen6_Library_Package $package, ?array $settings, array $map, array $create, array $args): array|\WP_Error
    {
        $post = $this->store->post($post_id);
        if (null === $post || $this->store->has_layout($post_id) || '' !== trim($post->post_content)) {
            Oxygen6_Request_Error::raise('Importing into post_id needs a blank post (no layout, no content); leave post_id out to import as a new post.');
        }
        if ($post->post_type !== $package->post_type()) {
            Oxygen6_Request_Error::raise("Post {$post_id} has type \"{$post->post_type}\"; this export holds type \"{$package->post_type()}\".");
        }
        if ([] !== $create) {
            Oxygen6_Request_Error::raise('This export places Components this site does not have; leave post_id out to import it as a new post, which creates them too.');
        }
        $refusal = $this->guard->can_edit($post_id);
        if (null !== $refusal) {
            return $refusal;
        }

        $doc          = $this->tree($package->tree(), $map, $package);
        $template     = $package->is_template();
        $operation_id = $this->snapshots->write_post($post_id, $args, function () use ($post_id, $doc, $settings, $template): void {
            if ($template) {
                $this->store->save_settings($post_id, $settings);
            }
            $this->store->save_document($post_id, $doc);
        });

        return $this->result($operation_id, $post_id, $map, $package);
    }

    /**
     * @param array<string,mixed>|null $settings
     * @param array<int,int> $map
     * @param array<int,array<string,mixed>> $create
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    private function as_new(Oxygen6_Library_Package $package, ?array $settings, array $map, array $create, array $args): array|\WP_Error
    {
        $type = $package->post_type();
        if ($package->is_template()) {
            $status = 'publish';
            $fields = ['post_title' => sanitize_text_field($package->title()), 'post_type' => $type, 'post_status' => $status];
        } else {
            $data   = $package->item['postData'] ?? null;
            $status = Oxygen6_Values::str(Oxygen6_Values::at($args, 'element', 'status') ?? Oxygen6_Values::at($data, 'post_status') ?? 'draft');
            if (! in_array($status, self::STATUSES, true)) {
                Oxygen6_Request_Error::raise("status must be draft, pending, private or publish; got '{$status}'.");
            }
            $fields = [
                'post_title'   => sanitize_text_field($package->title()),
                'post_name'    => sanitize_title(Oxygen6_Values::str(Oxygen6_Values::at($data, 'post_name'))),
                'post_content' => Oxygen6_Values::str(Oxygen6_Values::at($data, 'post_content')),
                'post_excerpt' => Oxygen6_Values::str(Oxygen6_Values::at($data, 'post_excerpt')),
                'post_type'    => $type,
                'post_status'  => $status,
            ];
        }
        $refusal = $this->guard->can_create($type, in_array($status, ['publish', 'private'], true));
        if (null === $refusal && [] !== $create) {
            $refusal = $this->guard->can_create(Oxygen6_Store::KINDS['block'], true);
        }
        if (null !== $refusal) {
            return $refusal;
        }

        $created = [];
        try {
            $components = [];
            foreach ($create as $component) {
                $id           = $this->store->insert_post(['post_title' => sanitize_text_field(Oxygen6_Values::str($component['title'] ?? null)), 'post_type' => Oxygen6_Store::KINDS['block'], 'post_status' => 'publish']);
                $created[]    = $id;
                $components[] = [$id, $component];

                $map[ Oxygen6_Values::int($component['id'] ?? null) ] = $id;
            }
            foreach ($components as [$id, $component]) {
                $settings_row = $component['settings'] ?? null;
                $this->store->save_settings($id, is_array($settings_row) ? Oxygen6_Values::map($settings_row) : null);
                $this->store->save_document($id, $this->tree(Oxygen6_Library_Package::tree_of($component), $map, $package, true));
            }

            $post_id = $this->store->insert_post($fields);
            array_unshift($created, $post_id);
            if ($package->is_template()) {
                $this->store->save_settings($post_id, $settings);
            }
            $this->store->save_document($post_id, $this->tree($package->tree(), $map, $package));
            $operation_id = $this->snapshots->record_creation($created, $args);
        } catch (\Throwable $e) {
            $this->store->remove_created($created);
            throw $e;
        }

        return $this->result($operation_id, $post_id, $map, $package);
    }

    /**
     * Decode a tree, rewrite the source links (templates and Components
     * only, as Oxygen does) and map Component ids.
     *
     * @param array<int,int> $map
     */
    private function tree(string $json, array $map, Oxygen6_Library_Package $package, bool $component = false): object
    {
        $source = rtrim($package->source_url, '/');
        $local  = rtrim($this->store->site_url(), '/');
        if (($component || $package->is_template()) && '' !== $source && $source !== $local) {
            $regex = '/"((' . preg_quote($source, '/') . ')((?!\/wp-content\/)[^"]+))"/';
            $json  = (string) preg_replace($regex, '"' . str_replace(['\\', '$'], ['\\\\', '\\$'], $local) . '${3}"', $json);
        }
        $doc = Breakdance_Tree::decode($json);
        if (null === $doc) {
            Oxygen6_Request_Error::raise('An Oxygen tree in the export does not decode.');
        }

        return [] === $map ? $doc : $this->map_ids($doc, $map);
    }

    /**
     * The decoded value with every Component id on an ID_KEYS key that $map
     * holds replaced by the local id, at any depth (objects, and objects in
     * lists).
     *
     * @param array<int,int> $map
     */
    private function map_ids(object $value, array $map): object
    {
        $fields = get_object_vars($value);
        foreach ($fields as $key => $item) {
            if (is_int($item) && in_array($key, self::ID_KEYS, true) && isset($map[ $item ])) {
                $fields[ $key ] = $map[ $item ];
            } elseif (is_object($item)) {
                $fields[ $key ] = $this->map_ids($item, $map);
            } elseif (is_array($item)) {
                $fields[ $key ] = $this->map_list($item, $map);
            }
        }

        return (object) $fields;
    }

    /**
     * @param array<mixed> $list
     * @param array<int,int> $map
     * @return array<mixed>
     */
    private function map_list(array $list, array $map): array
    {
        foreach ($list as $index => $entry) {
            if (is_object($entry)) {
                $list[ $index ] = $this->map_ids($entry, $map);
            }
        }

        return $list;
    }

    /**
     * @param array<int,int> $map
     * @return array<string,mixed>
     */
    private function result(string $operation_id, int $post_id, array $map, Oxygen6_Library_Package $package): array
    {
        $design = $this->store->design();

        return [
            'operation_id'   => $operation_id,
            'post_id'        => $post_id,
            'builder'        => 'oxygen',
            'hash'           => $this->store->layout_hash($post_id),
            'components'     => $map,
            'design_missing' => [
                'variables' => $this->missing($package->variables['variables'] ?? [], $design['variables']['variables'] ?? []),
                'selectors' => $this->missing($package->selectors['selectors'] ?? [], $design['selectors']['selectors'] ?? []),
            ],
        ];
    }

    /** @return string[] ids in $incoming that $local lacks */
    private function missing(mixed $incoming, mixed $local): array
    {
        $have = [];
        foreach (is_array($local) ? $local : [] as $entry) {
            if (is_array($entry) && is_scalar($entry['id'] ?? null)) {
                $have[ (string) $entry['id'] ] = true;
            }
        }
        $out = [];
        foreach (is_array($incoming) ? $incoming : [] as $entry) {
            $id = is_array($entry) && is_scalar($entry['id'] ?? null) ? (string) $entry['id'] : '';
            if ('' !== $id && ! isset($have[ $id ])) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
