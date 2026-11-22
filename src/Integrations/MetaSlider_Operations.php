<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The MetaSlider op definitions (issue #705): schemas, refusals and
 * handlers over MetaSlider_Store.
 *
 * Every op needs MetaSlider's own capability (the metaslider_capability
 * filter, edit_others_posts by default, ml-slider.php) and the per-object
 * edit_post checks MetaSlider 3.114 makes (metaslider_verify_slideshow_for_ajax
 * and _slide_for_ajax), declared as objects so the dispatcher runs them.
 * Writes are undoable: create-slider and add-slides record what they
 * created (Post_Creation_Snapshot, the undo trashes the posts, and a trashed
 * slide is no longer read by MetaSlider), reorder-slides snapshots each
 * slide post before its menu_order changes, and update-slide snapshots the
 * one slide post (fields and meta).
 */
final class MetaSlider_Operations
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const OPT_IN_FILTER = 'wpmcp_enable_metaslider_write';

    public const MAX_PAGE_SIZE = 100;

    public const MAX_SLIDES = 200;

    /** @param \Closure(): (true|array<string, mixed>) $presence */
    public function __construct(
        private readonly MetaSlider_Store $store,
        private readonly \Closure $presence
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $images = [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_SLIDES, 'items' => $id ];
        $base   = [ 'capability' => 'edit_posts', 'requires' => $this->presence ];
        $write  = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_metaslider_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $slider = static fn (string $access): array => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
        $image  = [ 'image_ids' => [ 'type' => 'post', 'access' => 'read' ] ];

        return [
            'metaslider-list-sliders'   => $base + [
                'mode'         => 'read',
                'description'  => 'List MetaSlider sliders by title: id, title, status, type, width, height, slide_count, shortcode. search, page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                ],
                'validate'     => fn (): ?array => $this->cap_refusal(),
                'handler'      => fn (array $args): array => $this->list_sliders($args),
            ],
            'metaslider-get-slider'     => $base + [
                'mode'         => 'read',
                'objects'      => $slider('read'),
                'description'  => 'Read one MetaSlider slider: settings and its slides in display order (id, type, image, title, alt, caption, url, new_window, hidden)',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => fn (array $args): ?array => $this->slider_refusal(Typed::int($args['id'] ?? null)),
                'handler'      => fn (array $args): array => [ 'slider' => $this->view(Typed::int($args['id'] ?? null), true) ],
            ],
            'metaslider-create-slider'  => $write + [
                'self_snapshotting' => true,
                'objects'           => $image,
                'description'       => 'Create a published MetaSlider slider: title required; image_ids (media library images, one image slide each, in order), type (flex default), width, height. Undo: rollback-session trashes it and its slides',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => [
                        'title'     => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'image_ids' => [ 'minItems' => 0 ] + $images,
                        'type'      => [ 'type' => 'string', 'enum' => MetaSlider_Store::TYPES ],
                        'width'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000 ],
                        'height'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000 ],
                    ],
                    'required'             => [ 'title' ],
                    'additionalProperties' => false,
                ],
                'validate'          => fn (array $args): ?array => $this->cap_refusal() ?? $this->image_refusal(Typed::ints($args['image_ids'] ?? null)),
                'handler'           => fn (array $args, array $context): array => $this->create($args, $context),
            ],
            'metaslider-add-slides'     => $write + [
                'self_snapshotting' => true,
                'objects'           => $slider('write') + $image,
                'description'       => 'Append an image slide per media library image to a MetaSlider slider, after its last slide. Undo: rollback-session trashes the new slides',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id, 'image_ids' => $images ],
                    'required'             => [ 'id', 'image_ids' ],
                    'additionalProperties' => false,
                ],
                'validate'          => fn (array $args): ?array => $this->slider_refusal(Typed::int($args['id'] ?? null)) ?? $this->image_refusal(Typed::ints($args['image_ids'] ?? null)),
                'handler'           => fn (array $args, array $context): array => $this->add_slides($args, $context),
            ],
            'metaslider-reorder-slides' => $write + [
                'self_snapshotting' => true,
                'objects'           => $slider('write') + [ 'slide_ids' => [ 'type' => 'post', 'own_type' => true, 'access' => 'write' ] ],
                'description'       => 'Set the display order of a MetaSlider slider: slide_ids must be exactly its current slides (get-slider lists them). Undo: rollback-session',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id, 'slide_ids' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_SLIDES, 'items' => $id ] ],
                    'required'             => [ 'id', 'slide_ids' ],
                    'additionalProperties' => false,
                ],
                'validate'          => fn (array $args): ?array => $this->reorder_refusal($args),
                'handler'           => fn (array $args, array $context): array => $this->reorder($args, $context),
            ],
            'metaslider-update-slide'   => $write + [
                'objects'      => [ 'slide_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'write' ] ],
                'description'  => 'Change a MetaSlider image slide: caption, url (empty removes the link), new_window, title and alt (either stops inheriting the image\'s own). Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'slide_id'   => $id,
                        'caption'    => [ 'type' => 'string', 'maxLength' => 5000 ],
                        'url'        => [ 'type' => 'string', 'maxLength' => 2000 ],
                        'new_window' => [ 'type' => 'boolean' ],
                        'title'      => [ 'type' => 'string', 'maxLength' => 500 ],
                        'alt'        => [ 'type' => 'string', 'maxLength' => 500 ],
                    ],
                    'required'             => [ 'slide_id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'     => fn (array $args): ?array => $this->slide_refusal(Typed::int($args['slide_id'] ?? null)),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => Typed::int($args['slide_id'] ?? null) ],
                'handler'      => fn (array $args): array => $this->update_slide($args),
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    /**
     * @param array<string, mixed> $data
     * @return array{code: string, message: string, data: array<string, mixed>}
     */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    public static function capability(): string
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying MetaSlider's own capability filter, as its screens do; wpmcp adds no callback to it.
        return Typed::str(apply_filters('metaslider_capability', 'edit_others_posts'));
    }

    /** @return array{code: string, message: string, data: array<string, mixed>}|null */
    private function cap_refusal(): ?array
    {
        $cap = self::capability();
        if (current_user_can($cap)) {
            return null;
        }
        return self::refusal('operation_denied', sprintf('This needs the %s capability, the one MetaSlider asks for (its metaslider_capability filter).', $cap), [ 'reason' => 'capability', 'capability' => $cap ]);
    }

    /** @return array{code: string, message: string, data: array<string, mixed>}|null */
    private function slider_refusal(int $id): ?array
    {
        if (! $this->store->is_slider($id)) {
            return self::refusal('slider_not_found', sprintf('%d is not a MetaSlider slider.', $id), [ 'id' => $id ]);
        }
        return $this->cap_refusal();
    }

    /** @return array{code: string, message: string, data: array<string, mixed>}|null */
    private function slide_refusal(int $slide): ?array
    {
        if (0 === $this->store->slider_of($slide)) {
            return self::refusal('slide_not_found', sprintf('%d is not a MetaSlider slide of a slider.', $slide), [ 'slide_id' => $slide ]);
        }
        return $this->cap_refusal();
    }

    /**
     * @param list<int> $ids
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    private function image_refusal(array $ids): ?array
    {
        foreach ($ids as $image) {
            if (! wp_attachment_is_image($image)) {
                return self::refusal('image_not_found', sprintf('%d is not an image in the media library.', $image), [ 'image_id' => $image ]);
            }
        }
        return null;
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    private function reorder_refusal(array $args): ?array
    {
        $id      = Typed::int($args['id'] ?? null);
        $refused = $this->slider_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        $current = $this->store->slide_ids($id);
        $given   = Typed::ints($args['slide_ids'] ?? null);
        $a       = $current;
        $b       = $given;
        sort($a);
        sort($b);
        if ($a !== $b) {
            return self::refusal('slides_mismatch', 'slide_ids must list every slide of the slider exactly once; get-slider has them.', [ 'slide_ids' => $current ]);
        }
        return null;
    }

    // ------------------------------------------------------------ handlers

    /** @return array<string, mixed> */
    private function view(int $id, bool $full): array
    {
        $post     = get_post($id);
        $settings = $this->store->settings($id);
        $slides   = $this->store->slide_ids($id);
        $out      = [
            'id'          => $id,
            'title'       => $post instanceof \WP_Post ? $post->post_title : '',
            'status'      => $post instanceof \WP_Post ? $post->post_status : '',
            'type'        => Typed::str($settings['type'] ?? MetaSlider_Store::DEFAULTS['type']),
            'width'       => Typed::int($settings['width'] ?? MetaSlider_Store::DEFAULTS['width']),
            'height'      => Typed::int($settings['height'] ?? MetaSlider_Store::DEFAULTS['height']),
            'slide_count' => count($slides),
            'shortcode'   => sprintf('[metaslider id="%d"]', $id),
        ];
        if ($full) {
            $out['settings'] = array_intersect_key($settings, array_flip([ 'type', 'width', 'height', 'effect', 'delay', 'animationSpeed', 'navigation', 'links', 'autoPlay', 'random', 'theme' ])) + [ 'width' => $out['width'], 'height' => $out['height'] ];
            $out['slides']   = array_map(fn (int $slide): array => $this->store->slide($slide), array_slice($slides, 0, self::MAX_SLIDES));
        }
        return $out;
    }

    /**
     * @param array<mixed> $args
     * @return array{sliders: list<array<string, mixed>>, total: int}
     */
    private function list_sliders(array $args): array
    {
        $found = $this->store->sliders(trim(Typed::str($args['search'] ?? '')), Typed::int($args['page'] ?? 1), Typed::int($args['per_page'] ?? 20));
        return [ 'sliders' => array_map(fn (\WP_Post $p): array => $this->view($p->ID, false), $found['sliders']), 'total' => $found['total'] ];
    }

    /**
     * @param array<mixed> $args
     * @param array<mixed> $context
     * @return array{result: array<string, mixed>, operation_ids: list<string>}
     */
    private function create(array $args, array $context): array
    {
        $settings = Typed::map(array_intersect_key($args, array_flip([ 'type', 'width', 'height' ])));
        $id       = $this->store->insert_slider(sanitize_text_field(Typed::str($args['title'] ?? null)), $settings);
        $created  = [ $id ];
        foreach (Typed::ints($args['image_ids'] ?? null) as $image) {
            $created[] = $this->store->insert_image_slide($id, $image);
        }
        $op = Post_Creation_Snapshot::record(Typed::str($context['tool_name'] ?? null), $created, [ 'operation' => $context['operation'] ?? null, 'args' => $args ], Typed::str($context['session_id'] ?? null));

        return [ 'result' => [ 'slider' => $this->view($id, true) ], 'operation_ids' => [ $op ] ];
    }

    /**
     * @param array<mixed> $args
     * @param array<mixed> $context
     * @return array{result: array<string, mixed>, operation_ids: list<string>}
     */
    private function add_slides(array $args, array $context): array
    {
        $id      = Typed::int($args['id'] ?? null);
        $created = [];
        foreach (Typed::ints($args['image_ids'] ?? null) as $image) {
            $created[] = $this->store->insert_image_slide($id, $image);
        }
        $op = Post_Creation_Snapshot::record(Typed::str($context['tool_name'] ?? null), $created, [ 'operation' => $context['operation'] ?? null, 'args' => $args ], Typed::str($context['session_id'] ?? null));

        return [ 'result' => [ 'added' => $created, 'slider' => $this->view($id, true) ], 'operation_ids' => [ $op ] ];
    }

    /**
     * @param array<mixed> $args
     * @param array<mixed> $context
     * @return array{result: array<string, mixed>, operation_ids: list<string>}
     */
    private function reorder(array $args, array $context): array
    {
        $id  = Typed::int($args['id'] ?? null);
        $ops = [];
        foreach (Typed::ints($args['slide_ids'] ?? null) as $index => $slide) {
            if ((int) get_post_field('menu_order', $slide) === $index + 1) {
                continue;
            }
            $out   = Safe_Mutation::run(
                [
                    'object_type' => 'post',
                    'object_id'   => $slide,
                    'session_id'  => Typed::str($context['session_id'] ?? null),
                    'tool_name'   => Typed::str($context['tool_name'] ?? null),
                    'args'        => [ 'operation' => Typed::str($context['operation'] ?? null), 'args' => $args ],
                ],
                function () use ($slide, $index): bool {
                    $this->store->set_order($slide, $index + 1);
                    return true;
                }
            );
            $ops[] = Typed::str($out['operation_id'] ?? null);
        }

        return [ 'result' => [ 'slider' => $this->view($id, true) ], 'operation_ids' => $ops ];
    }

    /**
     * @param array<mixed> $args
     * @return array{slide: array<string, mixed>}
     */
    private function update_slide(array $args): array
    {
        $slide  = Typed::int($args['slide_id'] ?? null);
        $fields = [];
        foreach ([ 'caption', 'title', 'alt' ] as $key) {
            if (isset($args[ $key ])) {
                $fields[ $key ] = 'caption' === $key ? wp_kses_post(Typed::str($args[ $key ])) : sanitize_text_field(Typed::str($args[ $key ]));
            }
        }
        if (isset($args['url'])) {
            $fields['url'] = esc_url_raw(Typed::str($args['url']));
        }
        if (isset($args['new_window'])) {
            $fields['new_window'] = (bool) $args['new_window'];
        }
        $this->store->update_slide($slide, $fields);

        return [ 'slide' => $this->store->slide($slide) ];
    }
}
