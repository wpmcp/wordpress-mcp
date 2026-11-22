<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * NextGEN Gallery's storage (issue #705), as NextGEN Gallery 4.6 keeps it
 * in tables of its own (src/Legacy/admin/install.php): ngg_gallery holds
 * one row per gallery (gid, name, slug, path relative to the site root,
 * title, galdesc, author), and ngg_pictures one row per image (pid, its
 * gallery in galleryid, filename inside the gallery's folder, alttext,
 * description, exclude, sortorder). Each row also has a mirror post for
 * fields that are not columns (extras_post_id); the columns read and
 * written here are real columns, so the mirror is left alone, as
 * NextGEN's own sort screen leaves it (src/Legacy/admin/manage-sort.php).
 *
 * After a raw write the image mapper's query cache and NextGEN's rendered
 * gallery transients are stale, so both are flushed, as that screen does.
 *
 * Rows come back typed (a NULL column reads as '' or 0).
 * Only storage lives here; NextGen_Operations validates and dispatches.
 *
 * @phpstan-type Gallery_Row array{gid: int, name: string, slug: string, path: string, title: string, galdesc: string, pageid: int, previewpic: int, author: int}
 * @phpstan-type Image_Row array{pid: int, galleryid: int, filename: string, alttext: string, description: string, exclude: int, sortorder: int, imagedate: string}
 */
final class NextGen_Store
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- NextGEN Gallery's own tables, read and written live, as its own sort screen writes them.

    public const GALLERIES = 'ngg_gallery';

    public const PICTURES = 'ngg_pictures';

    private const GALLERY_COLUMNS = 'gid, name, slug, path, title, galdesc, pageid, previewpic, author';

    private const IMAGE_COLUMNS = 'pid, galleryid, filename, alttext, description, exclude, sortorder, imagedate';

    public function table(string $name): string
    {
        $db = Typed::db();
        return (null === $db ? '' : $db->prefix) . $name;
    }

    public function tables_exist(): bool
    {
        foreach ([ self::GALLERIES, self::PICTURES ] as $name) {
            if (is_wp_error(Database_Guard::valid_table($this->table($name)))) {
                return false;
            }
        }
        return true;
    }

    /** @return Gallery_Row|null */
    public function gallery(int $id): ?array
    {
        $db = Typed::db();
        if (null === $db) {
            return null;
        }
        $row = $db->get_row($db->prepare('SELECT ' . self::GALLERY_COLUMNS . ' FROM %i WHERE gid = %d', $this->table(self::GALLERIES), $id), ARRAY_A);
        return is_array($row) ? self::gallery_row($row) : null;
    }

    /** @return array{galleries: list<Gallery_Row>, total: int} */
    public function galleries(string $search, int $page, int $per_page): array
    {
        $db = Typed::db();
        if (null === $db) {
            return [ 'galleries' => [], 'total' => 0 ];
        }
        $where = '1=1';
        $args  = [ $this->table(self::GALLERIES) ];
        if ('' !== $search) {
            $where  = '(title LIKE %s OR name LIKE %s)';
            $like   = '%' . $db->esc_like($search) . '%';
            $args[] = $like;
            $args[] = $like;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only literals and %s placeholders, one argument each.
        $total  = Typed::int($db->get_var($db->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", ...$args)));
        $args[] = $per_page;
        $args[] = ($page - 1) * $per_page;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only literals and %s placeholders, one argument each.
        $rows = $db->get_results($db->prepare('SELECT ' . self::GALLERY_COLUMNS . " FROM %i WHERE {$where} ORDER BY title ASC, gid ASC LIMIT %d OFFSET %d", ...$args), ARRAY_A);
        return [ 'galleries' => array_map(self::gallery_row(...), Typed::list($rows)), 'total' => $total ];
    }

    public function image_count(int $gallery): int
    {
        $db = Typed::db();
        if (null === $db) {
            return 0;
        }
        return Typed::int($db->get_var($db->prepare('SELECT COUNT(*) FROM %i WHERE galleryid = %d', $this->table(self::PICTURES), $gallery)));
    }

    /** @return list<Image_Row> The gallery's images in its stored order (sortorder, then pid). */
    public function images(int $gallery): array
    {
        $db = Typed::db();
        if (null === $db) {
            return [];
        }
        $rows = $db->get_results($db->prepare('SELECT ' . self::IMAGE_COLUMNS . ' FROM %i WHERE galleryid = %d ORDER BY sortorder ASC, pid ASC', $this->table(self::PICTURES), $gallery), ARRAY_A);
        return array_map(self::image_row(...), Typed::list($rows));
    }

    /** @return Image_Row|null */
    public function image(int $pid): ?array
    {
        $db = Typed::db();
        if (null === $db) {
            return null;
        }
        $row = $db->get_row($db->prepare('SELECT ' . self::IMAGE_COLUMNS . ' FROM %i WHERE pid = %d', $this->table(self::PICTURES), $pid), ARRAY_A);
        return is_array($row) ? self::image_row($row) : null;
    }

    /**
     * The image's URL: the gallery folder (relative to the site root) and its file name.
     *
     * @param Gallery_Row $gallery
     * @param Image_Row   $image
     */
    public static function image_url(array $gallery, array $image): string
    {
        return site_url('/' . trim($gallery['path'], '/') . '/' . rawurlencode($image['filename']));
    }

    /** Put one image at a place in its gallery, as the sort screen numbers them (from 1); the caller flushes. */
    public function set_image_order(int $gallery, int $pid, int $sortorder): void
    {
        $db = Typed::db();
        if (null === $db || false === $db->update($this->table(self::PICTURES), [ 'sortorder' => $sortorder ], [ 'pid' => $pid, 'galleryid' => $gallery ])) {
            throw new Operation_Refused('update_failed', sprintf('Could not reorder image %d: %s', $pid, self::last_error($db)));
        }
    }

    /** @param array<string, string|int> $set */
    public function update_image(int $pid, array $set): void
    {
        $db = Typed::db();
        if (null === $db || false === $db->update($this->table(self::PICTURES), $set, [ 'pid' => $pid ])) {
            throw new Operation_Refused('update_failed', sprintf('Could not update image %d: %s', $pid, self::last_error($db)));
        }
        $this->flush();
    }

    /** Drop NextGEN's cached image queries and rendered galleries. A no-op while it is not loaded. */
    public function flush(): void
    {
        $mapper = self::call_static('\\Imagely\\NGG\\DataMappers\\Image', 'get_instance');
        if (is_object($mapper) && method_exists($mapper, 'flush_query_cache')) {
            $mapper->flush_query_cache();
        }
        self::call_static('\\Imagely\\NGG\\Util\\Transient', 'flush', 'displayed_gallery_rendering');
    }

    /**
     * One static method of NextGEN's own classes, or null while it is not loaded.
     *
     * @return mixed Whatever NextGEN returns, read by the caller.
     */
    private static function call_static(string $class, string $method, mixed ...$args): mixed
    {
        $callable = [ $class, $method ];
        if (! class_exists($class) || ! is_callable($callable)) {
            return null;
        }
        return $callable(...$args);
    }

    private static function last_error(?\wpdb $db): string
    {
        return null === $db ? 'no database connection' : $db->last_error;
    }

    /** @return Gallery_Row */
    private static function gallery_row(mixed $row): array
    {
        $row = Typed::map($row);
        return [
            'gid'        => Typed::int($row['gid'] ?? null),
            'name'       => Typed::str($row['name'] ?? null),
            'slug'       => Typed::str($row['slug'] ?? null),
            'path'       => Typed::str($row['path'] ?? null),
            'title'      => Typed::str($row['title'] ?? null),
            'galdesc'    => Typed::str($row['galdesc'] ?? null),
            'pageid'     => Typed::int($row['pageid'] ?? null),
            'previewpic' => Typed::int($row['previewpic'] ?? null),
            'author'     => Typed::int($row['author'] ?? null),
        ];
    }

    /** @return Image_Row */
    private static function image_row(mixed $row): array
    {
        $row = Typed::map($row);
        return [
            'pid'         => Typed::int($row['pid'] ?? null),
            'galleryid'   => Typed::int($row['galleryid'] ?? null),
            'filename'    => Typed::str($row['filename'] ?? null),
            'alttext'     => Typed::str($row['alttext'] ?? null),
            'description' => Typed::str($row['description'] ?? null),
            'exclude'     => Typed::int($row['exclude'] ?? null),
            'sortorder'   => Typed::int($row['sortorder'] ?? null),
            'imagedate'   => Typed::str($row['imagedate'] ?? null),
        ];
    }
}
