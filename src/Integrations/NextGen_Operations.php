<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The NextGEN Gallery op definitions (issue #705): schemas, refusals and
 * handlers over NextGen_Store.
 *
 * Galleries and images are rows of NextGEN's own tables, not posts, so the
 * ops apply NextGEN's own capabilities (src/Util/Installer.php
 * set_role_caps(), Security::can_manage_owned()): reads need "NextGEN
 * Gallery overview", writes "NextGEN Manage gallery" and, for a gallery
 * another user owns, "NextGEN Manage others gallery", plus manage_options,
 * which undoing a raw table row takes. Each write is undoable:
 * update-image snapshots the image row (db_rows) and reorder-images each
 * row it renumbers, in the call's session. NextGEN's caches are flushed
 * after each write and after an undo (NextGen_Pack::after_rows_restored).
 *
 * Creating galleries and adding images are left out: NextGEN imports an
 * image by copying its file into the gallery folder, making thumbnails and
 * a mirror post (src/DataStorage/Manager.php import_image_file()), and none
 * of that can be taken back through a snapshot.
 *
 * @phpstan-import-type Gallery_Row from NextGen_Store
 * @phpstan-import-type Image_Row from NextGen_Store
 * @phpstan-type Refusal array{code: string, message: string, data: array<string, mixed>}
 * @phpstan-type Row_Snapshot array{object_type: string, object_id: string, extra_snapshot_data: array{table: string, operation: string, primary_key: list<string>, where: array{pid: int}, set: array<string, string|int>, rows: array<mixed>}}
 */
final class NextGen_Operations
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const OPT_IN_FILTER = 'wpmcp_enable_nextgen_write';

    public const CAP_READ = 'NextGEN Gallery overview';

    public const CAP_MANAGE = 'NextGEN Manage gallery';

    public const CAP_OTHERS = 'NextGEN Manage others gallery';

    public const MAX_PAGE_SIZE = 100;

    public const MAX_IMAGES = 500;

    /** @param \Closure(): (true|array<string, mixed>) $presence */
    public function __construct(private readonly NextGen_Store $store, private readonly \Closure $presence)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $id    = [ 'type' => 'integer', 'minimum' => 1 ];
        $base  = [ 'capability' => 'edit_posts', 'requires' => $this->presence ];
        $write = [
            'mode'               => 'write',
            'capability'         => 'manage_options',
            'requires'           => $this->presence,
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_nextgen_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];

        return [
            'nextgen-list-galleries' => $base + [
                'mode'         => 'read',
                'description'  => 'List NextGEN Gallery galleries by title: id, title, slug, path, author, image_count, shortcode. search, page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                ],
                'validate'     => fn (): ?array => $this->read_refusal(),
                'handler'      => fn (array $args): array => $this->list_galleries($args),
            ],
            'nextgen-get-gallery'    => $base + [
                'mode'         => 'read',
                'description'  => 'Read one NextGEN Gallery gallery: its images in stored order (id, filename, alttext, description, excluded, sortorder, url)',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => fn (array $args): ?array => $this->read_refusal() ?? $this->gallery_refusal(self::arg_int($args, 'id')),
                'handler'      => fn (array $args): array => [ 'gallery' => $this->view($this->existing_gallery(self::arg_int($args, 'id')), true) ],
            ],
            'nextgen-reorder-images' => $write + [
                'self_snapshotting' => true,
                'description'       => 'Set the stored order of a NextGEN Gallery gallery (sortorder, shown when the gallery sorts by it, NextGEN\'s default): image_ids must be exactly its images. Undo: rollback-session',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id, 'image_ids' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_IMAGES, 'items' => $id ] ],
                    'required'             => [ 'id', 'image_ids' ],
                    'additionalProperties' => false,
                ],
                'validate'          => fn (array $args): ?array => $this->reorder_refusal($args),
                'handler'           => fn (array $args, array $context): array => $this->reorder($args, $context),
            ],
            'nextgen-update-image'   => $write + [
                'description'  => 'Change a NextGEN Gallery image: alttext (its title), description, excluded (true hides it from the gallery). Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'image_id'    => $id,
                        'alttext'     => [ 'type' => 'string', 'maxLength' => 500 ],
                        'description' => [ 'type' => 'string', 'maxLength' => 5000 ],
                        'excluded'    => [ 'type' => 'boolean' ],
                    ],
                    'required'             => [ 'image_id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'     => fn (array $args): ?array => $this->image_refusal(self::arg_int($args, 'image_id')),
                'snapshot'     => fn (array $args): ?array => $this->row_snapshot(self::arg_int($args, 'image_id'), self::image_set($args)),
                'handler'      => fn (array $args): array => $this->update_image($args),
            ],
        ];
    }

    /**
     * One integer argument; the schema has checked it, and a numeric string reads as its number.
     *
     * @param array<mixed> $args
     */
    private static function arg_int(array $args, string $key, int $default = 0): int
    {
        return Typed::int($args[ $key ] ?? null, $default);
    }

    // ------------------------------------------------------------ refusals

    /**
     * @param array<string, mixed> $data
     * @return Refusal
     */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** @return Refusal|null */
    private static function cap_refusal(string $cap): ?array
    {
        return current_user_can($cap) ? null : self::refusal('operation_denied', sprintf('This needs NextGEN Gallery\'s "%s" capability.', $cap), [ 'reason' => 'capability', 'capability' => $cap ]);
    }

    /** @return Refusal|null */
    private function tables_refusal(): ?array
    {
        return $this->store->tables_exist() ? null : self::refusal('nextgen_tables_missing', 'NextGEN Gallery has not created its tables on this site yet; open its dashboard once, then try again.');
    }

    /** @return Refusal|null */
    private function read_refusal(): ?array
    {
        return self::cap_refusal(self::CAP_READ) ?? $this->tables_refusal();
    }

    /** @return Refusal */
    private static function gallery_missing(int $id): array
    {
        return self::refusal('gallery_not_found', sprintf('%d is not a NextGEN Gallery gallery.', $id), [ 'id' => $id ]);
    }

    /** @return Refusal|null */
    private function gallery_refusal(int $id): ?array
    {
        return null === $this->store->gallery($id) ? self::gallery_missing($id) : null;
    }

    /**
     * A write on a gallery: its manage capability, and the others capability unless the caller owns it.
     *
     * @return Refusal|null
     */
    private function manage_refusal(int $gallery): ?array
    {
        $refused = self::cap_refusal(self::CAP_MANAGE) ?? $this->tables_refusal();
        if (null !== $refused) {
            return $refused;
        }
        $row = $this->store->gallery($gallery);
        if (null === $row) {
            return self::gallery_missing($gallery);
        }
        return get_current_user_id() === $row['author'] ? null : self::cap_refusal(self::CAP_OTHERS);
    }

    /** @return Refusal|null */
    private function image_refusal(int $pid): ?array
    {
        $refused = $this->tables_refusal();
        if (null !== $refused) {
            return $refused;
        }
        $image = $this->store->image($pid);
        if (null === $image) {
            return self::refusal('image_not_found', sprintf('%d is not a NextGEN Gallery image.', $pid), [ 'image_id' => $pid ]);
        }
        return $this->manage_refusal($image['galleryid']);
    }

    /**
     * @param array<mixed> $args
     * @return Refusal|null
     */
    private function reorder_refusal(array $args): ?array
    {
        $id      = self::arg_int($args, 'id');
        $refused = $this->manage_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        $current = array_column($this->store->images($id), 'pid');
        $given   = Typed::ints($args['image_ids'] ?? null);
        $a       = $current;
        $b       = $given;
        sort($a);
        sort($b);
        return $a === $b ? null : self::refusal('images_mismatch', 'image_ids must list every image of the gallery exactly once; get-gallery has them.', [ 'image_ids' => $current ]);
    }

    // ------------------------------------------------------------ handlers

    /**
     * The gallery a validated op names; gone only if it was deleted since validation.
     *
     * @return Gallery_Row
     */
    private function existing_gallery(int $id): array
    {
        $row = $this->store->gallery($id);
        if (null === $row) {
            $refusal = self::gallery_missing($id);
            throw new Operation_Refused($refusal['code'], $refusal['message'], $refusal['data']);
        }
        return $row;
    }

    /**
     * @param array<mixed> $args
     * @return array<string, string|int>
     */
    private static function image_set(array $args): array
    {
        $set = [];
        if (isset($args['alttext'])) {
            $set['alttext'] = sanitize_text_field(Typed::str($args['alttext']));
        }
        if (isset($args['description'])) {
            $set['description'] = wp_kses_post(Typed::str($args['description']));
        }
        if (isset($args['excluded'])) {
            // The schema lets 'false' and '0' through as booleans, so they read as false here.
            $set['exclude'] = Typed::bool($args['excluded']) ? 1 : 0;
        }
        return $set;
    }

    /**
     * A db_rows before-image of one image row.
     *
     * @param array<string, string|int> $set
     * @return Row_Snapshot|null
     */
    private function row_snapshot(int $pid, array $set): ?array
    {
        $table = Database_Guard::valid_table($this->store->table(NextGen_Store::PICTURES));
        if (is_wp_error($table)) {
            return null;
        }
        $where  = [ 'pid' => $pid ];
        $before = Database_Guard::before_image($table, $where, 1);
        if ([] === $before) {
            return null;
        }
        return [
            'object_type'         => 'db_rows',
            'object_id'           => $table,
            'extra_snapshot_data' => [ 'table' => $table, 'operation' => 'update', 'primary_key' => [ 'pid' ], 'where' => $where, 'set' => $set, 'rows' => $before ],
        ];
    }

    /**
     * @param Gallery_Row $gallery
     * @param Image_Row   $row
     * @return array{id: int, filename: string, alttext: string, description: string, excluded: bool, sortorder: int, url: string}
     */
    private static function image_view(array $gallery, array $row): array
    {
        return [
            'id'          => $row['pid'],
            'filename'    => $row['filename'],
            'alttext'     => $row['alttext'],
            'description' => $row['description'],
            'excluded'    => 1 === $row['exclude'],
            'sortorder'   => $row['sortorder'],
            'url'         => NextGen_Store::image_url($gallery, $row),
        ];
    }

    /**
     * @param Gallery_Row $row
     * @return array<string, mixed>
     */
    private function view(array $row, bool $full): array
    {
        $id  = $row['gid'];
        $out = [
            'id'          => $id,
            'title'       => $row['title'],
            'slug'        => $row['slug'],
            'path'        => $row['path'],
            'description' => $row['galdesc'],
            'author'      => $row['author'],
            'image_count' => $this->store->image_count($id),
            'shortcode'   => sprintf('[ngg src="galleries" ids="%d" display="basic_thumbnail"]', $id),
        ];
        if ($full) {
            $out['images'] = array_map(static fn (array $image): array => self::image_view($row, $image), array_slice($this->store->images($id), 0, self::MAX_IMAGES));
        }
        return $out;
    }

    /**
     * @param array<mixed> $args
     * @return array{galleries: list<array<string, mixed>>, total: int}
     */
    private function list_galleries(array $args): array
    {
        $page     = max(1, self::arg_int($args, 'page', 1));
        $per_page = min(self::MAX_PAGE_SIZE, max(1, self::arg_int($args, 'per_page', 20)));
        $found    = $this->store->galleries(trim(Typed::str($args['search'] ?? null)), $page, $per_page);
        return [ 'galleries' => array_map(fn (array $row): array => $this->view($row, false), $found['galleries']), 'total' => $found['total'] ];
    }

    /**
     * @param array<mixed> $args
     * @param array<mixed> $context The caller's session_id, tool_name and operation (Integration_Dispatcher::run_write()).
     * @return array{result: array{gallery: array<string, mixed>}, operation_ids: list<string>}
     */
    private function reorder(array $args, array $context): array
    {
        $id    = self::arg_int($args, 'id');
        $order = Typed::ints($args['image_ids'] ?? null);
        $ops   = [];
        foreach ($order as $index => $pid) {
            $snapshot = $this->row_snapshot($pid, [ 'sortorder' => $index + 1 ]);
            if (null === $snapshot || Typed::int(Typed::field(Typed::list($snapshot['extra_snapshot_data']['rows'])[0] ?? null, 'sortorder')) === $index + 1) {
                continue;
            }
            $out   = Safe_Mutation::run(
                $snapshot + [
                    'session_id' => Typed::str($context['session_id'] ?? null),
                    'tool_name'  => Typed::str($context['tool_name'] ?? null),
                    'args'       => [ 'operation' => Typed::str($context['operation'] ?? null), 'args' => $args ],
                ],
                function () use ($id, $pid, $index): bool {
                    $this->store->set_image_order($id, $pid, $index + 1);
                    return true;
                }
            );
            $ops[] = Typed::str($out['operation_id'] ?? null);
        }
        $this->store->flush();

        return [ 'result' => [ 'gallery' => $this->view($this->existing_gallery($id), true) ], 'operation_ids' => $ops ];
    }

    /**
     * @param array<mixed> $args
     * @return array{image: array{id: int, filename: string, alttext: string, description: string, excluded: bool, sortorder: int, url: string}}
     */
    private function update_image(array $args): array
    {
        $pid = self::arg_int($args, 'image_id');
        $this->store->update_image($pid, self::image_set($args));
        $image = $this->store->image($pid);
        if (null === $image) {
            throw new Operation_Refused('image_not_found', sprintf('%d is not a NextGEN Gallery image.', $pid), [ 'image_id' => $pid ]);
        }

        return [ 'image' => self::image_view($this->existing_gallery($image['galleryid']), $image) ];
    }
}
