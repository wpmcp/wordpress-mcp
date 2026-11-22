<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Smart Slider 3's storage (issue #705), as Smart Slider 3.5 keeps it in
 * tables of its own (Nextend/SmartSlider3/Install/Tables.php):
 * nextend2_smartslider3_sliders holds one row per slider (title, type,
 * params JSON, slider_status published or trash; a group is a slider of
 * type group), and nextend2_smartslider3_slides one row per slide, its
 * slider in `slider`, its place in `ordering` (from 1, as
 * ModelSlides::order() numbers it), title, description, published, and its
 * background image in params JSON (backgroundImage), stored as a URL or
 * with the $upload$ keyword for the uploads folder.
 *
 * Smart Slider renders from caches of its own, so every write ends with
 * its public Project::clearCache(), which clears the slider's cache groups
 * and marks it changed, as ModelSliders::refreshCache() does.
 *
 * Only storage lives here; SmartSlider_Operations validates and dispatches.
 */
final class SmartSlider_Store
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Smart Slider 3's own tables, read and written live; it has no WordPress API.

    public const SLIDERS = 'nextend2_smartslider3_sliders';

    public const SLIDES = 'nextend2_smartslider3_slides';

    /** Smart Slider's public API class; its static clearCache() drops a slider's caches. */
    private const PROJECT_API = 'Nextend\\SmartSlider3\\PublicApi\\Project';

    public function table(string $name): string
    {
        return (Typed::db()->prefix ?? '') . $name;
    }

    public function tables_exist(): bool
    {
        foreach ([ self::SLIDERS, self::SLIDES ] as $name) {
            if (is_wp_error(Database_Guard::valid_table($this->table($name)))) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed>|null A slider row, or null when it is missing or trashed. */
    public function slider(int $id): ?array
    {
        $db = Typed::db();
        if (null === $db) {
            return null;
        }
        $row = self::row($db->get_row($db->prepare('SELECT * FROM %i WHERE id = %d', $this->table(self::SLIDERS), $id), ARRAY_A));
        return null !== $row && 'trash' !== $row['slider_status'] ? $row : null;
    }

    /** @return array{sliders: list<array<string, mixed>>, total: int} */
    public function sliders(string $search, int $page, int $per_page): array
    {
        $db = Typed::db();
        if (null === $db) {
            return [ 'sliders' => [], 'total' => 0 ];
        }
        $where = "slider_status <> 'trash'";
        $args  = [ $this->table(self::SLIDERS) ];
        if ('' !== $search) {
            $where .= ' AND title LIKE %s';
            $args[] = '%' . $db->esc_like($search) . '%';
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only literals and %s placeholders, one argument each.
        $total  = Typed::int($db->get_var($db->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", ...$args)));
        $args[] = $per_page;
        $args[] = ($page - 1) * $per_page;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only literals and %s placeholders, one argument each.
        $rows = $db->get_results($db->prepare("SELECT * FROM %i WHERE {$where} ORDER BY title ASC, id ASC LIMIT %d OFFSET %d", ...$args), ARRAY_A);
        return [ 'sliders' => self::rows($rows), 'total' => $total ];
    }

    public function slide_count(int $slider): int
    {
        $db = Typed::db();
        if (null === $db) {
            return 0;
        }
        return Typed::int($db->get_var($db->prepare('SELECT COUNT(*) FROM %i WHERE slider = %d', $this->table(self::SLIDES), $slider)));
    }

    /** @return list<array<string, mixed>> The slider's slides in display order. */
    public function slides(int $slider): array
    {
        $db = Typed::db();
        if (null === $db) {
            return [];
        }
        return self::rows($db->get_results($db->prepare('SELECT * FROM %i WHERE slider = %d ORDER BY ordering ASC, id ASC', $this->table(self::SLIDES), $slider), ARRAY_A));
    }

    /** @return array<string, mixed>|null */
    public function slide(int $id): ?array
    {
        $db = Typed::db();
        if (null === $db) {
            return null;
        }
        return self::row($db->get_row($db->prepare('SELECT * FROM %i WHERE id = %d', $this->table(self::SLIDES), $id), ARRAY_A));
    }

    /** An image reference as a URL: Smart Slider's $upload$ keyword becomes the uploads URL. */
    public static function image_url(string $image): string
    {
        if (str_starts_with($image, '$upload$')) {
            return wp_upload_dir()['baseurl'] . substr($image, strlen('$upload$'));
        }
        return $image;
    }

    /** Put one slide at a place, as ModelSlides::order() numbers them (from 1); the caller refreshes. */
    public function set_slide_order(int $slider, int $slide, int $ordering): void
    {
        $db = self::writable_db();
        if (false === $db->update($this->table(self::SLIDES), [ 'ordering' => $ordering ], [ 'id' => $slide, 'slider' => $slider ])) {
            throw new Operation_Refused('update_failed', sprintf('Could not reorder slide %d: %s', $slide, $db->last_error));
        }
    }

    /** @param array<string, string|int> $set */
    public function update_slide(int $slide, array $set): void
    {
        $db  = self::writable_db();
        $row = $this->slide($slide);
        if (false === $db->update($this->table(self::SLIDES), $set, [ 'id' => $slide ])) {
            throw new Operation_Refused('update_failed', sprintf('Could not update slide %d: %s', $slide, $db->last_error));
        }
        $this->refresh(Typed::int($row['slider'] ?? 0));
    }

    /** Clear a slider's caches through Smart Slider's own public API. A no-op while it is not loaded. */
    public function refresh(int $slider): void
    {
        $clear = [ self::PROJECT_API, 'clearCache' ];
        if ($slider <= 0 || ! class_exists(self::PROJECT_API) || ! is_callable($clear)) {
            return;
        }
        call_user_func($clear, $slider);
    }

    /** Clear the caches of every slider that has slides, after an undo put rows back. */
    public function refresh_all(): void
    {
        $db = Typed::db();
        if (null === $db || ! $this->tables_exist()) {
            return;
        }
        foreach (Typed::ints($db->get_col($db->prepare('SELECT DISTINCT slider FROM %i', $this->table(self::SLIDES)))) as $slider) {
            $this->refresh($slider);
        }
    }

    /** The database for a write; outside WordPress there is none, and the write is refused. */
    private static function writable_db(): \wpdb
    {
        $db = Typed::db();
        if (null === $db) {
            throw new Operation_Refused('update_failed', 'The WordPress database is not available.');
        }
        return $db;
    }

    /** @return array<string, mixed>|null One ARRAY_A row as $wpdb hands it back, or null for no row. */
    private static function row(mixed $row): ?array
    {
        return is_array($row) ? Typed::map($row) : null;
    }

    /** @return list<array<string, mixed>> ARRAY_A rows as $wpdb hands them back. */
    private static function rows(mixed $rows): array
    {
        $out = [];
        foreach (Typed::list($rows) as $row) {
            if (is_array($row)) {
                $out[] = Typed::map($row);
            }
        }
        return $out;
    }
}
