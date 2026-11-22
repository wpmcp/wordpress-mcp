<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Envira Gallery op definitions (issue #705): schemas, refusals and
 * handlers over Envira_Store.
 *
 * Every op needs Envira's own capability (edit_envira_galleries, which
 * Envira grants with its permission policy; manage_options passes as in
 * its own checks), and the declared post objects carry edit_post on the
 * gallery, mapped through Envira's capabilities. An added image needs
 * edit_post on the attachment, as Envira's insert handler asks (author or
 * edit_others_posts); a removed one only has to be in the gallery.
 *
 * Writes are undoable within one session: create-gallery records the
 * gallery it made (Post_Creation_Snapshot, the undo trashes it), and every
 * attachment whose _eg_has_gallery changes, and every gallery whose items
 * change, is snapshotted as a post first. reorder-images touches only the
 * gallery. After an undo Envira's caches of the gallery are flushed
 * (Envira_Pack::after_post_restored).
 *
 * @phpstan-type Refusal array{code: string, message: string, data: array<string, mixed>}
 */
final class Envira_Operations
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const OPT_IN_FILTER = 'wpmcp_enable_envira_write';

    public const CAP = 'edit_envira_galleries';

    public const MAX_PAGE_SIZE = 100;

    public const MAX_IMAGES = 500;

    public const MAX_IMAGE_DETAILS = 200;

    /** @param \Closure(): (true|array<string, mixed>) $presence */
    public function __construct(private readonly Envira_Store $store, private readonly \Closure $presence)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $images  = [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_IMAGES, 'items' => $id ];
        $base    = [ 'capability' => 'edit_posts', 'requires' => $this->presence ];
        $write   = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_envira_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $gallery = static fn (string $access): array => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
        $image   = static fn (string $access): array => [ 'image_ids' => [ 'type' => 'post', 'access' => $access ] ];
        $change  = [
            'type'                 => 'object',
            'properties'           => [ 'id' => $id, 'image_ids' => $images ],
            'required'             => [ 'id', 'image_ids' ],
            'additionalProperties' => false,
        ];

        return [
            'envira-list-galleries' => $base + [
                'mode'         => 'read',
                'description'  => 'List Envira galleries by title: id, title, status, slug, image_count, shortcode. Filter by status, search. page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [ 'type' => 'string', 'enum' => Envira_Store::STATUSES ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                ],
                'validate'     => fn (): ?array => $this->cap_refusal(),
                'handler'      => fn (array $args): array => $this->list_galleries($args),
            ],
            'envira-get-gallery'    => $base + [
                'mode'         => 'read',
                'objects'      => $gallery('read'),
                'description'  => 'Read one Envira gallery: image_ids in display order and each image\'s status, title, alt, caption, link and src (first 200), shortcode',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => fn (array $args): ?array => $this->gallery_refusal(self::id($args)),
                'handler'      => fn (array $args): array => [ 'gallery' => $this->view(self::id($args), true) ],
            ],
            'envira-create-gallery' => $write + [
                'self_snapshotting' => true,
                'objects'           => $image('write'),
                'description'       => 'Create an Envira gallery: title required; image_ids (media library ids, display order), status draft (default) or publish. Undo: rollback-session trashes it and clears the images\' membership',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => [
                        'title'     => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
                        'image_ids' => [ 'minItems' => 0 ] + $images,
                        'status'    => [ 'type' => 'string', 'enum' => [ 'draft', 'publish' ] ],
                    ],
                    'required'             => [ 'title' ],
                    'additionalProperties' => false,
                ],
                'validate'          => fn (array $args): ?array => $this->create_refusal($args),
                'handler'           => fn (array $args, array $context): array => $this->create($args, $context),
            ],
            'envira-add-images'     => $write + [
                'self_snapshotting' => true,
                'objects'           => $gallery('write') + $image('write'),
                'description'       => 'Append media library images to an Envira gallery (ones already in it are skipped). Undo: rollback-session',
                'input_schema'      => $change,
                'validate'          => fn (array $args): ?array => $this->gallery_refusal(self::id($args)) ?? $this->image_refusal(self::as_array($args['image_ids'] ?? null)),
                'handler'           => fn (array $args, array $context): array => $this->change($args, $context, 'add'),
            ],
            'envira-remove-images'  => $write + [
                'self_snapshotting' => true,
                'objects'           => $gallery('write') + $image('read'),
                'description'       => 'Remove images from an Envira gallery (the media files stay). Undo: rollback-session',
                'input_schema'      => $change,
                'validate'          => fn (array $args): ?array => $this->gallery_refusal(self::id($args)),
                'handler'           => fn (array $args, array $context): array => $this->change($args, $context, 'remove'),
            ],
            'envira-reorder-images' => $write + [
                'objects'      => $gallery('write') + $image('read'),
                'description'  => 'Set the display order of an Envira gallery: image_ids must be exactly its current images; each keeps its settings. Undo: rollback-operation',
                'input_schema' => $change,
                'validate'     => fn (array $args): ?array => $this->reorder_refusal($args),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => self::id($args) ],
                'handler'      => fn (array $args): array => $this->reorder($args),
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    /** @param array<mixed> $args The gallery id an op names. */
    private static function id(array $args): int
    {
        return Typed::int($args['id'] ?? null);
    }

    /**
     * A JSON value read as (array) would, without the cast.
     *
     * @return array<mixed>
     */
    private static function as_array(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return get_object_vars($value);
        }
        return null === $value ? [] : [ $value ];
    }

    /**
     * @param array<string, mixed> $data
     * @return Refusal
     */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** @return Refusal|null */
    private function cap_refusal(): ?array
    {
        if (current_user_can('manage_options') || current_user_can(self::CAP)) {
            return null;
        }
        return self::refusal('operation_denied', sprintf('This needs the %s capability (Envira grants it with its gallery permissions).', self::CAP), [ 'reason' => 'capability', 'capability' => self::CAP ]);
    }

    /** @return Refusal|null */
    private function gallery_refusal(int $id): ?array
    {
        if (! $this->store->is_gallery($id)) {
            return self::refusal('gallery_not_found', sprintf('%d is not an Envira gallery.', $id), [ 'id' => $id ]);
        }
        return $this->cap_refusal();
    }

    /**
     * @param array<mixed> $ids
     * @return Refusal|null
     */
    private function image_refusal(array $ids): ?array
    {
        foreach ($ids as $value) {
            $image = Typed::int($value);
            if ('attachment' !== get_post_type($image)) {
                return self::refusal('image_not_found', sprintf('%d is not in the media library.', $image), [ 'image_id' => $image ]);
            }
        }
        return null;
    }

    /**
     * @param array<mixed> $args
     * @return Refusal|null
     */
    private function create_refusal(array $args): ?array
    {
        if (! post_type_exists(Envira_Store::GALLERY)) {
            return self::refusal('post_type_missing', 'Envira has not registered its gallery post type.');
        }
        if ('publish' === ($args['status'] ?? 'draft') && ! current_user_can('manage_options') && ! current_user_can('publish_envira_galleries')) {
            return self::refusal('operation_denied', 'Publishing a gallery needs the publish_envira_galleries capability; create it as a draft instead.', [ 'reason' => 'capability', 'capability' => 'publish_envira_galleries' ]);
        }
        return $this->cap_refusal() ?? $this->image_refusal(self::as_array($args['image_ids'] ?? []));
    }

    /**
     * @param array<mixed> $args
     * @return Refusal|null
     */
    private function reorder_refusal(array $args): ?array
    {
        $id      = self::id($args);
        $refused = $this->gallery_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        $current = $this->store->image_ids($id);
        $given   = Typed::ints(self::as_array($args['image_ids'] ?? null));
        $a       = $current;
        $b       = $given;
        sort($a);
        sort($b);
        return $a === $b ? null : self::refusal('images_mismatch', 'image_ids must list every image of the gallery exactly once; get-gallery has them.', [ 'image_ids' => $current ]);
    }

    // ------------------------------------------------------------ handlers

    /**
     * @param array<mixed> $ids
     * @return list<int> Unique positive ids, order kept.
     */
    private static function unique(array $ids): array
    {
        return array_values(array_unique(array_filter(Typed::ints($ids), static fn (int $id): bool => $id > 0)));
    }

    /** @return array<string, mixed> */
    private function view(int $id, bool $full): array
    {
        $post  = get_post($id);
        $found = $post instanceof \WP_Post;
        $data  = $this->store->data($id);
        $items = $this->store->items($id);
        $out   = [
            'id'          => $id,
            'title'       => $found ? $post->post_title : '',
            'status'      => $found ? $post->post_status : '',
            'slug'        => Typed::str(Typed::field($data['config'] ?? null, 'slug')),
            'image_count' => count($items),
            'shortcode'   => sprintf('[envira-gallery id="%d"]', $id),
            'modified'    => $found ? $post->post_modified : '',
        ];
        if (! $full) {
            return $out;
        }
        $out['image_ids'] = Typed::ints(array_keys($items));
        $images           = [];
        foreach (array_slice($items, 0, self::MAX_IMAGE_DETAILS, true) as $image => $value) {
            $item     = self::as_array($value);
            $images[] = [
                'id'      => Typed::int($image),
                'status'  => Typed::str($item['status'] ?? null, 'active'),
                'title'   => Typed::str($item['title'] ?? null),
                'alt'     => Typed::str($item['alt'] ?? null),
                'caption' => Typed::str($item['caption'] ?? null),
                'link'    => Typed::str($item['link'] ?? null),
                'src'     => Typed::str($item['src'] ?? null),
            ];
        }
        $out['images'] = $images;
        return $out;
    }

    /**
     * @param array<mixed> $args
     * @return array{galleries: list<array<string, mixed>>, total: int}
     */
    private function list_galleries(array $args): array
    {
        $found = $this->store->galleries(isset($args['status']) ? Typed::str($args['status']) : null, trim(Typed::str($args['search'] ?? '')), Typed::int($args['page'] ?? 1), Typed::int($args['per_page'] ?? 20));
        return [ 'galleries' => array_map(fn (\WP_Post $p): array => $this->view($p->ID, false), $found['galleries']), 'total' => $found['total'] ];
    }

    /**
     * Run one write under a post snapshot in the call's session; returns its operation id.
     *
     * @param array<mixed> $args
     * @param array<mixed> $context
     * @param callable(): mixed $write
     */
    private function under_snapshot(int $post_id, array $args, array $context, callable $write): string
    {
        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => Typed::str($context['session_id'] ?? null),
                'tool_name'   => Typed::str($context['tool_name'] ?? null),
                'args'        => [ 'operation' => Typed::str($context['operation'] ?? null), 'args' => $args ],
            ],
            static function () use ($write): bool {
                $write();
                return true;
            }
        );
        return Typed::str($out['operation_id'] ?? null);
    }

    /**
     * @param list<int> $images
     * @param array<mixed> $args
     * @param array<mixed> $context
     * @return list<string> The memberships changed, each under its own snapshot.
     */
    private function memberships(array $images, int $gallery, bool $member, array $args, array $context): array
    {
        $ops = [];
        foreach ($images as $image) {
            if (in_array($gallery, $this->store->galleries_of($image), true) === $member) {
                continue;
            }
            $ops[] = $this->under_snapshot($image, $args, $context, fn () => $this->store->set_membership($image, $gallery, $member));
        }
        return $ops;
    }

    /**
     * @param array<mixed> $args
     * @param array<mixed> $context
     * @return array{result: array<string, mixed>, operation_ids: list<string>}
     */
    private function create(array $args, array $context): array
    {
        $title  = sanitize_text_field(Typed::str($args['title'] ?? null));
        $id     = $this->store->insert_gallery($title, Typed::str($args['status'] ?? 'draft'));
        $ops    = [ Post_Creation_Snapshot::record(Typed::str($context['tool_name'] ?? null), [ $id ], [ 'operation' => $context['operation'] ?? null, 'args' => $args ], Typed::str($context['session_id'] ?? null)) ];
        $images = self::unique(self::as_array($args['image_ids'] ?? []));
        $ops    = array_merge($ops, $this->memberships($images, $id, true, $args, $context));
        $items  = [];
        foreach ($images as $image) {
            $items[ $image ] = $this->store->new_item($image);
        }
        $this->store->save_items($id, $items);

        return [ 'result' => [ 'gallery' => $this->view($id, true) ], 'operation_ids' => $ops ];
    }

    /**
     * @param array<mixed> $args
     * @param array<mixed> $context
     * @return array{result: array<string, mixed>, operation_ids: list<string>}
     */
    private function change(array $args, array $context, string $how): array
    {
        $id    = self::id($args);
        $given = self::unique(self::as_array($args['image_ids'] ?? null));
        $items = $this->store->items($id);
        if ('add' === $how) {
            $given = array_values(array_diff($given, Typed::ints(array_keys($items))));
            foreach ($given as $image) {
                $items[ $image ] = $this->store->new_item($image);
            }
        } else {
            $given = array_values(array_intersect($given, Typed::ints(array_keys($items))));
            foreach ($given as $image) {
                unset($items[ $image ]);
            }
        }
        if ([] === $given) {
            return [ 'result' => [ 'gallery' => $this->view($id, true), 'changed' => [] ], 'operation_ids' => [] ];
        }
        $ops   = $this->memberships($given, $id, 'add' === $how, $args, $context);
        $ops[] = $this->under_snapshot($id, $args, $context, fn () => $this->store->save_items($id, $items));

        return [ 'result' => [ 'gallery' => $this->view($id, true), 'changed' => $given ], 'operation_ids' => $ops ];
    }

    /**
     * @param array<mixed> $args
     * @return array{gallery: array<string, mixed>}
     */
    private function reorder(array $args): array
    {
        $id    = self::id($args);
        $items = $this->store->items($id);
        $order = [];
        foreach (Typed::ints(self::as_array($args['image_ids'] ?? null)) as $image) {
            $order[ $image ] = $items[ $image ] ?? null;
        }
        $this->store->save_items($id, $order);

        return [ 'gallery' => $this->view($id, true) ];
    }
}
