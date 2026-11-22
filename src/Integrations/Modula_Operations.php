<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Post_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Modula op definitions (issue #705): schemas, refusals and handlers
 * over Modula_Store.
 *
 * Modula galleries take core's post capabilities, so the declared post
 * objects carry the whole check (edit_post on the gallery, read on each
 * image), as Modula's own editor checks edit_post. Writes are undoable:
 * create-gallery records the gallery it made (Post_Creation_Snapshot, the
 * undo trashes it); add-images, remove-images and reorder-images run under
 * a post snapshot of the gallery, whose meta holds both of Modula's image
 * copies.
 */
final class Modula_Operations
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const OPT_IN_FILTER = 'wpmcp_enable_modula_write';

    public const MAX_PAGE_SIZE = 100;

    public const MAX_IMAGES = 500;

    public const MAX_IMAGE_DETAILS = 200;

    /** @param \Closure(): (true|array<string, mixed>) $presence */
    public function __construct(
        private readonly Modula_Store $store,
        private readonly \Closure $presence
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $images  = [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_IMAGES, 'items' => $id ];
        $base    = [ 'capability' => 'edit_posts', 'requires' => $this->presence ];
        $write   = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_modula_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $gallery = static fn (string $access): array => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
        $image   = [ 'image_ids' => [ 'type' => 'post', 'access' => 'read' ] ];
        $change  = static fn (string $how, string $description): array => $write + [
            'objects'      => $gallery('write') + $image,
            'description'  => $description,
            'input_schema' => [
                'type'                 => 'object',
                'properties'           => [ 'id' => $id, 'image_ids' => $images ],
                'required'             => [ 'id', 'image_ids' ],
                'additionalProperties' => false,
            ],
            'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => Typed::int($args['id'] ?? null) ],
        ];

        return [
            'modula-list-galleries' => $base + [
                'mode'         => 'read',
                'description'  => 'List Modula galleries by title: id, title, status, type, image_count, shortcode. Filter by status, search. page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [ 'type' => 'string', 'enum' => Modula_Store::STATUSES ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                ],
                'handler'      => fn (array $args): array => $this->list_galleries($args),
            ],
            'modula-get-gallery'    => $base + [
                'mode'         => 'read',
                'objects'      => $gallery('read'),
                'description'  => 'Read one Modula gallery: image_ids in display order and each image\'s title, alt, link and url (first 200), type, shortcode',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => fn (array $args): ?array => $this->gallery_refusal(Typed::int($args['id'] ?? null)),
                'handler'      => fn (array $args): array => [ 'gallery' => $this->view(Typed::int($args['id'] ?? null), true) ],
            ],
            'modula-create-gallery' => $write + [
                'self_snapshotting' => true,
                'objects'           => $image,
                'description'       => 'Create a Modula gallery: title required; image_ids (media library ids, display order), status draft (default) or publish. Undo: rollback-session trashes it',
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
            'modula-add-images'     => $change('add', 'Append media library images to a Modula gallery (ones already in it are skipped). Undo: rollback-operation') + [
                'validate' => fn (array $args): ?array => $this->gallery_refusal(Typed::int($args['id'] ?? null)) ?? $this->image_refusal(Typed::list($args['image_ids'] ?? null)),
                'handler'  => fn (array $args): array => $this->change($args, 'add'),
            ],
            'modula-remove-images'  => $change('remove', 'Remove images from a Modula gallery (the media files stay). Undo: rollback-operation') + [
                'validate' => fn (array $args): ?array => $this->gallery_refusal(Typed::int($args['id'] ?? null)),
                'handler'  => fn (array $args): array => $this->change($args, 'remove'),
            ],
            'modula-reorder-images' => $change('reorder', 'Set the display order of a Modula gallery: image_ids must be exactly its current images; each image keeps its settings. Undo: rollback-operation') + [
                'validate' => fn (array $args): ?array => $this->reorder_refusal($args),
                'handler'  => fn (array $args): array => $this->change($args, 'reorder'),
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

    /** @return array{code: string, message: string, data: array<string, mixed>}|null */
    private function gallery_refusal(int $id): ?array
    {
        return $this->store->is_gallery($id) ? null : self::refusal('gallery_not_found', sprintf('%d is not a Modula gallery.', $id), [ 'id' => $id ]);
    }

    /**
     * @param list<mixed> $ids
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    private function image_refusal(array $ids): ?array
    {
        foreach (Typed::ints($ids) as $image) {
            if ('attachment' !== get_post_type($image)) {
                return self::refusal('image_not_found', sprintf('%d is not in the media library.', $image), [ 'image_id' => $image ]);
            }
        }
        return null;
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    private function create_refusal(array $args): ?array
    {
        if (! post_type_exists(Modula_Store::GALLERY)) {
            return self::refusal('post_type_missing', 'Modula has not registered its gallery post type.');
        }
        if ('publish' === ($args['status'] ?? 'draft') && ! current_user_can('publish_posts')) {
            return self::refusal('operation_denied', 'Publishing a gallery needs the publish_posts capability; create it as a draft instead.', [ 'reason' => 'capability', 'capability' => 'publish_posts' ]);
        }
        return $this->image_refusal(Typed::list($args['image_ids'] ?? []));
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    private function reorder_refusal(array $args): ?array
    {
        $id      = Typed::int($args['id'] ?? null);
        $refused = $this->gallery_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        $current = $this->store->image_ids($id);
        $given   = Typed::ints($args['image_ids'] ?? null);
        $a       = $current;
        $b       = $given;
        sort($a);
        sort($b);
        return $a === $b ? null : self::refusal('images_mismatch', 'image_ids must list every image of the gallery exactly once; get-gallery has them.', [ 'image_ids' => $current ]);
    }

    // ------------------------------------------------------------ handlers

    /**
     * Unique positive ids, order kept.
     *
     * @return list<int>
     */
    private static function unique(mixed $ids): array
    {
        return array_values(array_unique(array_filter(Typed::ints($ids), static fn (int $id): bool => $id > 0)));
    }

    /** The attachment's URL, or '' when it has none. */
    private static function url(int $image): string
    {
        $url = wp_get_attachment_url($image);
        return false === $url ? '' : $url;
    }

    /** @return array<string, mixed> */
    private function view(int $id, bool $full): array
    {
        $post = get_post($id);
        $rows = $this->store->rows($id);
        $out  = [
            'id'          => $id,
            'title'       => $post instanceof \WP_Post ? $post->post_title : '',
            'status'      => $post instanceof \WP_Post ? $post->post_status : '',
            'type'        => Typed::str($this->store->settings($id)['type'] ?? ''),
            'image_count' => count($rows),
            'shortcode'   => sprintf('[modula id="%d"]', $id),
            'modified'    => $post instanceof \WP_Post ? $post->post_modified : '',
        ];
        if (! $full) {
            return $out;
        }
        $out['image_ids'] = array_map(static fn (array $row): int => Modula_Store::row_id($row), $rows);
        $out['images']    = array_map(
            static function (array $row): array {
                $image = Modula_Store::row_id($row);
                $title = Typed::str($row['title'] ?? '');
                $alt   = Typed::str($row['alt'] ?? '');
                return [
                    'id'    => $image,
                    'title' => '' === $title ? get_the_title($image) : $title,
                    'alt'   => '' === $alt ? Typed::str(get_post_meta($image, '_wp_attachment_image_alt', true)) : $alt,
                    'link'  => Typed::str($row['link'] ?? ''),
                    'url'   => self::url($image),
                ];
            },
            array_slice($rows, 0, self::MAX_IMAGE_DETAILS)
        );
        return $out;
    }

    /**
     * @param array<mixed> $args
     * @return array{galleries: list<array<string, mixed>>, total: int}
     */
    private function list_galleries(array $args): array
    {
        $status = isset($args['status']) ? Typed::str($args['status']) : null;
        $search = trim(Typed::str($args['search'] ?? ''));
        $found  = $this->store->galleries($status, $search, Typed::int($args['page'] ?? 1), Typed::int($args['per_page'] ?? 20));
        return [ 'galleries' => array_map(fn (\WP_Post $p): array => $this->view($p->ID, false), $found['galleries']), 'total' => $found['total'] ];
    }

    /**
     * @param array<mixed> $args
     * @param array<mixed> $context
     * @return array{result: array{gallery: array<string, mixed>}, operation_ids: list<string>}
     */
    private function create(array $args, array $context): array
    {
        $id     = $this->store->insert_gallery(sanitize_text_field(Typed::str($args['title'] ?? null)), Typed::str($args['status'] ?? 'draft'));
        $op     = Post_Creation_Snapshot::record(Typed::str($context['tool_name'] ?? null), [ $id ], [ 'operation' => $context['operation'] ?? null, 'args' => $args ], Typed::str($context['session_id'] ?? null));
        $images = self::unique($args['image_ids'] ?? []);
        if ([] !== $images) {
            $this->store->save_rows($id, array_map(fn (int $image): array => $this->store->new_row($id, $image), $images));
        }

        return [ 'result' => [ 'gallery' => $this->view($id, true) ], 'operation_ids' => [ $op ] ];
    }

    /**
     * @param array<mixed> $args
     * @return array{gallery: array<string, mixed>}
     */
    private function change(array $args, string $how): array
    {
        $id    = Typed::int($args['id'] ?? null);
        $given = self::unique($args['image_ids'] ?? null);
        $rows  = $this->store->rows($id);
        if ('add' === $how) {
            $have = array_map(static fn (array $row): int => Modula_Store::row_id($row), $rows);
            foreach (array_diff($given, $have) as $image) {
                $rows[] = $this->store->new_row($id, $image);
            }
        } elseif ('remove' === $how) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => ! in_array(Modula_Store::row_id($row), $given, true)));
        } else {
            $by   = array_column($rows, null, 'id');
            $rows = array_map(static fn (int $image): array => $by[ $image ], $given);
        }
        $this->store->save_rows($id, $rows);

        return [ 'gallery' => $this->view($id, true) ];
    }
}
