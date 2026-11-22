<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Oxygen 6 templates, headers and footers with display conditions (issue
 * #805), written the way Oxygen's saveTemplate() writes them:
 *
 * - save_template with element {title, kind: template|header|footer,
 *   settings} saves one node of the page (path) or the whole page as a new
 *   post of Oxygen's own type, published (Oxygen forces those types to
 *   publish), with the settings in `_oxygen_template_settings`. Recorded as
 *   a creation: rollback trashes it. The page is left as it is.
 * - set_conditions with element {settings} replaces a template's settings,
 *   pinned to the hash of the stored settings row (get-builder-content
 *   scope conditions with its post_id) and undone through its post
 *   snapshot.
 *
 * Settings are checked by Oxygen6_Settings_Validator against the types and
 * conditions Oxygen registers.
 */
final class Oxygen6_Template_Writer
{
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
    public function save(array $args): array|\WP_Error
    {
        $post_id = Oxygen6_Values::int($args['post_id'] ?? null);
        $element = is_array($args['element'] ?? null) ? $args['element'] : [];
        $kind    = Oxygen6_Values::str($element['kind'] ?? null);
        if (! in_array($kind, Oxygen6_Store::CONDITION_KINDS, true)) {
            Oxygen6_Request_Error::raise("save_template kind is template, header or footer (or leave it out to save a Component); got '{$kind}'.");
        }
        $title = is_string($element['title'] ?? null) ? trim($element['title']) : '';
        if ('' === $title) {
            Oxygen6_Request_Error::raise("save_template needs element {title, kind, settings}: the {$kind} name to save under.");
        }
        if (! array_key_exists('settings', $element)) {
            Oxygen6_Request_Error::raise("save_template kind {$kind} needs element settings: {type, ruleGroups, priority?} (read get-builder-content scope conditions).");
        }
        $type = Oxygen6_Store::KINDS[ $kind ];
        if (! post_type_exists($type)) {
            return new \WP_Error('builder_not_loaded', "Saving the {$kind} needs Oxygen 6 loaded on this site, since the {$type} post type is its own.");
        }
        $vocabulary = $this->vocabulary->load();
        if (null === $vocabulary) {
            return Oxygen6_Vocabulary_Source::not_loaded();
        }
        $refusal = $this->guard->can_edit($post_id) ?? $this->guard->can_create($type, true);
        if (null !== $refusal) {
            return $refusal;
        }

        $settings = (new Oxygen6_Settings_Validator($vocabulary, $this->store))->validate($element['settings'], $kind);
        $page     = $this->store->document($post_id);
        if (null === $page) {
            Oxygen6_Request_Error::raise('This page holds no Oxygen layout to save.');
        }
        $path = Oxygen6_Values::str($args['path'] ?? null);
        $doc  = $page;
        if ('' !== $path) {
            [$doc] = Breakdance_Tree::add(Oxygen_Page::blank(), '', null, Oxygen6_Values::map(Breakdance_Tree::node($page, $path)));
        }

        $id = $this->store->insert_post(['post_title' => sanitize_text_field($title), 'post_type' => $type, 'post_status' => 'publish']);
        try {
            $this->store->save_settings($id, $settings->to_array());
            $this->store->save_document($id, $doc);
            $operation_id = $this->snapshots->record_creation([$id], $args);
        } catch (\Throwable $e) {
            $this->store->remove_created([$id]);
            throw $e;
        }

        return [
            'operation_id'  => $operation_id,
            'post_id'       => $post_id,
            'builder'       => 'oxygen',
            'template_id'   => $id,
            'type'          => $kind,
            'hash'          => $this->store->layout_hash($id),
            'settings_hash' => $this->store->settings_hash($id),
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public function set_conditions(array $args): array|\WP_Error
    {
        $post_id = Oxygen6_Values::int($args['post_id'] ?? null);
        $post    = $this->store->post($post_id);
        $kind    = null !== $post ? $this->store->kind_of($post->post_type) : null;
        if (null === $kind || ! in_array($kind, Oxygen6_Store::CONDITION_KINDS, true)) {
            Oxygen6_Request_Error::raise('set_conditions needs post_id: an Oxygen template, header or footer (see get-builder-content scope templates).');
        }
        if (true === ($this->store->settings($post_id)['fallback'] ?? false)) {
            Oxygen6_Request_Error::raise("Template {$post_id} is one of Oxygen's fallback templates, whose conditions Oxygen manages itself.");
        }
        $element = is_array($args['element'] ?? null) ? $args['element'] : [];
        if (! array_key_exists('settings', $element)) {
            Oxygen6_Request_Error::raise('set_conditions needs element {settings: {type, ruleGroups, priority?, parentId?, disabled?}}; it replaces the stored settings.');
        }
        $vocabulary = $this->vocabulary->load();
        if (null === $vocabulary) {
            return Oxygen6_Vocabulary_Source::not_loaded();
        }
        $type    = get_post_type_object($post->post_type);
        $live    = null === $type ? '' : Oxygen6_Values::str(Oxygen6_Values::at($type->cap, 'publish_posts'));
        $refusal = $this->guard->can_edit($post_id);
        if (null === $refusal && null !== $type && ! current_user_can($live)) {
            $refusal = new \WP_Error('forbidden', "Changing where an Oxygen {$kind} shows needs the {$live} capability.", ['status' => 403, 'capability' => $live]);
        }
        $refusal = $refusal ?? $this->guard->hash_refusal(Oxygen6_Values::str($args['expected_hash'] ?? null), $this->store->settings_hash($post_id), "an Oxygen {$kind}'s conditions");
        if (null !== $refusal) {
            return $refusal;
        }

        $settings     = (new Oxygen6_Settings_Validator($vocabulary, $this->store))->validate($element['settings'], $kind, $post_id)->to_array();
        $operation_id = $this->snapshots->write_post($post_id, $args, function () use ($post_id, $settings): void {
            $this->store->save_settings($post_id, $settings);
        });

        return [
            'operation_id' => $operation_id,
            'post_id'      => $post_id,
            'builder'      => 'oxygen',
            'settings'     => $settings,
            'hash'         => $this->store->settings_hash($post_id),
        ];
    }
}
