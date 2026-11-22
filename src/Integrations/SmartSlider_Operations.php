<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Smart Slider 3 op definitions (issue #705): schemas, refusals and
 * handlers over SmartSlider_Store.
 *
 * Sliders and slides are rows of Smart Slider's own tables, not posts, so
 * the ops apply its own capabilities: reads need smartslider (its menu
 * capability), writes smartslider_edit and unfiltered_html, as its
 * WordPressAcl asks for every edit, plus manage_options, which undoing a
 * raw table row takes. Each write is undoable: update-slide snapshots the
 * slide's row (db_rows), and reorder-slides snapshots each row it renumbers
 * in the call's session. Smart Slider's caches are cleared after each write
 * and after an undo (SmartSlider_Pack::after_rows_restored).
 *
 * Adding a slide is left out: a new row cannot be taken back through a
 * db_rows snapshot, which restores only rows that existed before the write.
 *
 * @phpstan-type Refusal array{code: string, message: string, data: array<string, mixed>}
 * @phpstan-type RowSnapshot array{object_type: string, object_id: string, extra_snapshot_data: array{table: string, operation: string, primary_key: list<string>, where: array{id: int}, set: array<string, string|int>, rows: array<mixed>}}
 */
final class SmartSlider_Operations
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const OPT_IN_FILTER = 'wpmcp_enable_smartslider_write';

    public const MAX_PAGE_SIZE = 100;

    public const MAX_SLIDES = 200;

    /** @param \Closure(): (true|array<string, mixed>) $presence The pack's presence check, run by the dispatcher before every op. */
    public function __construct(private readonly SmartSlider_Store $store, private readonly \Closure $presence)
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
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_smartslider_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];

        return [
            'smartslider-list-sliders'   => $base + [
                'mode'         => 'read',
                'description'  => 'List Smart Slider 3 sliders (trashed ones left out) by title: id, title, alias, type, status, slide_count, shortcode. search, page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                ],
                'validate'     => fn (): ?array => $this->read_refusal(),
                'handler'      => fn (array $args): array => $this->list_sliders($args),
            ],
            'smartslider-get-slider'     => $base + [
                'mode'         => 'read',
                'description'  => 'Read one Smart Slider 3 slider: its slides in display order (id, title, description, published, publish window, image, generator_id)',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => fn (array $args): ?array => $this->read_refusal() ?? $this->slider_refusal(self::id($args, 'id')),
                'handler'      => fn (array $args): array => [ 'slider' => $this->view($this->store->slider(self::id($args, 'id')) ?? [], true) ],
            ],
            'smartslider-reorder-slides' => $write + [
                'self_snapshotting' => true,
                'description'       => 'Set the display order of a Smart Slider 3 slider: slide_ids must be exactly its slides (get-slider lists them). Undo: rollback-session',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id, 'slide_ids' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_SLIDES, 'items' => $id ] ],
                    'required'             => [ 'id', 'slide_ids' ],
                    'additionalProperties' => false,
                ],
                'validate'          => fn (array $args): ?array => $this->reorder_refusal($args),
                'handler'           => fn (array $args, array $context): array => $this->reorder($args, $context),
            ],
            'smartslider-update-slide'   => $write + [
                'description'  => 'Change a Smart Slider 3 slide: title, description, published (false hides it). Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'slide_id'    => $id,
                        'title'       => [ 'type' => 'string', 'maxLength' => 500 ],
                        'description' => [ 'type' => 'string', 'maxLength' => 5000 ],
                        'published'   => [ 'type' => 'boolean' ],
                    ],
                    'required'             => [ 'slide_id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'     => fn (array $args): ?array => $this->write_refusal() ?? $this->slide_refusal(self::id($args, 'slide_id')),
                'snapshot'     => fn (array $args): ?array => $this->row_snapshot(self::id($args, 'slide_id'), self::slide_set($args)),
                'handler'      => fn (array $args): array => $this->update_slide($args),
            ],
        ];
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
    private function tables_refusal(): ?array
    {
        return $this->store->tables_exist() ? null : self::refusal('smartslider_tables_missing', 'Smart Slider 3 has not created its tables on this site yet; open its dashboard once, then try again.');
    }

    /** @return Refusal|null */
    private function read_refusal(): ?array
    {
        if (! current_user_can('smartslider')) {
            return self::refusal('operation_denied', 'This needs the smartslider capability, the one Smart Slider 3 gives its menu.', [ 'reason' => 'capability', 'capability' => 'smartslider' ]);
        }
        return $this->tables_refusal();
    }

    /** @return Refusal|null */
    private function write_refusal(): ?array
    {
        foreach ([ 'smartslider_edit', 'unfiltered_html' ] as $cap) {
            if (! current_user_can($cap)) {
                return self::refusal('operation_denied', sprintf('This needs the %s capability; Smart Slider 3 asks for smartslider_edit and unfiltered_html for every edit.', $cap), [ 'reason' => 'capability', 'capability' => $cap ]);
            }
        }
        return $this->tables_refusal();
    }

    /** @return Refusal|null */
    private function slider_refusal(int $id): ?array
    {
        return null === $this->store->slider($id) ? self::refusal('slider_not_found', sprintf('%d is not a Smart Slider 3 slider.', $id), [ 'id' => $id ]) : null;
    }

    /** @return Refusal|null */
    private function slide_refusal(int $id): ?array
    {
        $slide = $this->store->slide($id);
        if (null === $slide || null === $this->store->slider(Typed::int($slide['slider'] ?? null))) {
            return self::refusal('slide_not_found', sprintf('%d is not a slide of a Smart Slider 3 slider.', $id), [ 'slide_id' => $id ]);
        }
        return null;
    }

    /**
     * @param array<mixed> $args
     * @return Refusal|null
     */
    private function reorder_refusal(array $args): ?array
    {
        $id      = self::id($args, 'id');
        $refused = $this->write_refusal() ?? $this->slider_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        $current = array_map(static fn (array $row): int => Typed::int($row['id'] ?? null), $this->store->slides($id));
        $a       = $current;
        $b       = Typed::ints($args['slide_ids'] ?? null);
        sort($a);
        sort($b);
        return $a === $b ? null : self::refusal('slides_mismatch', 'slide_ids must list every slide of the slider exactly once; get-slider has them.', [ 'slide_ids' => $current ]);
    }

    // ------------------------------------------------------------ handlers

    /**
     * An integer argument the schema has already checked (an integer or an integral numeric string).
     *
     * @param array<mixed> $args
     */
    private static function id(array $args, string $key): int
    {
        return Typed::int($args[ $key ] ?? null);
    }

    /**
     * A schema boolean as WordPress reads it: rest_is_boolean() also lets
     * through 'true', 'false', '1', '0', 1 and 0, and 'false' is false here.
     */
    private static function flag(mixed $value): bool
    {
        return is_bool($value) || is_int($value) || is_string($value) ? rest_sanitize_boolean($value) : false;
    }

    /**
     * @param array<mixed> $args
     * @return array<string, string|int>
     */
    private static function slide_set(array $args): array
    {
        $set = [];
        if (isset($args['title'])) {
            $set['title'] = sanitize_text_field(Typed::str($args['title']));
        }
        if (isset($args['description'])) {
            $set['description'] = wp_kses_post(Typed::str($args['description']));
        }
        if (isset($args['published'])) {
            $set['published'] = self::flag($args['published']) ? 1 : 0;
        }
        return $set;
    }

    /**
     * A db_rows before-image of one slide row.
     *
     * @param array<string, string|int> $set
     * @return RowSnapshot|null
     */
    private function row_snapshot(int $slide, array $set): ?array
    {
        $table = Database_Guard::valid_table($this->store->table(SmartSlider_Store::SLIDES));
        if (is_wp_error($table)) {
            return null;
        }
        $where  = [ 'id' => $slide ];
        $before = Database_Guard::before_image($table, $where, 1);
        if ([] === $before) {
            return null;
        }
        return [
            'object_type'         => 'db_rows',
            'object_id'           => $table,
            'extra_snapshot_data' => [ 'table' => $table, 'operation' => 'update', 'primary_key' => [ 'id' ], 'where' => $where, 'set' => $set, 'rows' => $before ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, title: string, description: string, published: bool, publish_up: string, publish_down: string, ordering: int, image: string, generator_id: int}
     */
    private static function slide_view(array $row): array
    {
        $params     = json_decode(Typed::str($row['params'] ?? ''), true);
        $background = Typed::str(Typed::field($params, 'backgroundImage'));
        $image      = '' !== $background && '0' !== $background ? $background : Typed::str($row['thumbnail'] ?? '');
        return [
            'id'           => Typed::int($row['id'] ?? null),
            'title'        => Typed::str($row['title'] ?? null),
            'description'  => Typed::str($row['description'] ?? null),
            'published'    => 1 === Typed::int($row['published'] ?? null),
            'publish_up'   => Typed::str($row['publish_up'] ?? null),
            'publish_down' => Typed::str($row['publish_down'] ?? null),
            'ordering'     => Typed::int($row['ordering'] ?? null),
            'image'        => SmartSlider_Store::image_url($image),
            'generator_id' => Typed::int($row['generator_id'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function view(array $row, bool $full): array
    {
        $id  = Typed::int($row['id'] ?? null);
        $out = [
            'id'          => $id,
            'title'       => Typed::str($row['title'] ?? null),
            'alias'       => Typed::str($row['alias'] ?? ''),
            'type'        => Typed::str($row['type'] ?? null),
            'status'      => Typed::str($row['slider_status'] ?? null),
            'slide_count' => $this->store->slide_count($id),
            'shortcode'   => sprintf('[smartslider3 slider="%d"]', $id),
        ];
        if ($full) {
            $out['slides'] = array_map(self::slide_view(...), array_slice($this->store->slides($id), 0, self::MAX_SLIDES));
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
        return [ 'sliders' => array_map(fn (array $row): array => $this->view($row, false), $found['sliders']), 'total' => $found['total'] ];
    }

    /**
     * @param array<mixed> $args
     * @param array<mixed> $context The dispatcher's self-snapshotting context: session_id, tool_name, operation.
     * @return array{result: array{slider: array<string, mixed>}, operation_ids: list<string>}
     */
    private function reorder(array $args, array $context): array
    {
        $id  = self::id($args, 'id');
        $ops = [];
        foreach (Typed::ints($args['slide_ids'] ?? null) as $index => $slide) {
            $snapshot = $this->row_snapshot($slide, [ 'ordering' => $index + 1 ]);
            if (null === $snapshot || Typed::int(Typed::field(Typed::list($snapshot['extra_snapshot_data']['rows'])[0] ?? null, 'ordering')) === $index + 1) {
                continue;
            }
            $out   = Safe_Mutation::run(
                $snapshot + [
                    'session_id' => Typed::str($context['session_id'] ?? null),
                    'tool_name'  => Typed::str($context['tool_name'] ?? null),
                    'args'       => [ 'operation' => Typed::str($context['operation'] ?? null), 'args' => $args ],
                ],
                function () use ($id, $slide, $index): bool {
                    $this->store->set_slide_order($id, $slide, $index + 1);
                    return true;
                }
            );
            $ops[] = Typed::str($out['operation_id'] ?? null);
        }
        $this->store->refresh($id);

        return [ 'result' => [ 'slider' => $this->view($this->store->slider($id) ?? [], true) ], 'operation_ids' => $ops ];
    }

    /**
     * @param array<mixed> $args
     * @return array{slide: array<string, mixed>}
     */
    private function update_slide(array $args): array
    {
        $slide = self::id($args, 'slide_id');
        $this->store->update_slide($slide, self::slide_set($args));

        return [ 'slide' => self::slide_view($this->store->slide($slide) ?? []) ];
    }
}
