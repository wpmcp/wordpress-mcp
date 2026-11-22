<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Jetpack Forms' own storage (issue #676), read in Jetpack 16.4
 * (jetpack-forms, Feedback): a response is a `feedback` post with
 * post_status publish (the inbox), spam or trash, comment_status open
 * (unread) or closed (read), post_parent the jetpack_form post or the page
 * the form sits on, and, from the v2 format on, post_content holding the
 * JSON of subject, ip, user_agent, the source and fields (key, label,
 * value, type). Older plain-text responses are reported without fields.
 *
 * Only this class reads or writes those posts.
 */
final class Jetpack_Forms_Store
{
    public const POST_TYPE = 'feedback';

    /** status arg => post_status. */
    public const STATUSES = [ 'inbox' => 'publish', 'spam' => 'spam', 'trash' => 'trash' ];

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function entry(int $id): ?\WP_Post
    {
        $post = get_post($id);
        return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ? $post : null;
    }

    /**
     * Counts per source (post_parent) and status, unread in the inbox.
     *
     * @return array<int, array{inbox: int, spam: int, trash: int, unread: int}>
     */
    public function sources(): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one grouped count over the posts table; WP_Query has no GROUP BY.
        $rows = (array) $this->db->get_results($this->db->prepare(
            "SELECT post_parent, post_status, COUNT(*) AS n, SUM(CASE WHEN comment_status = 'open' THEN 1 ELSE 0 END) AS unread FROM %i WHERE post_type = %s AND post_status IN ('publish', 'spam', 'trash') GROUP BY post_parent, post_status",
            $this->db->posts,
            self::POST_TYPE
        ), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $id          = (int) $row['post_parent'];
            $out[ $id ]  = $out[ $id ] ?? [ 'inbox' => 0, 'spam' => 0, 'trash' => 0, 'unread' => 0 ];
            $status      = (string) array_search((string) $row['post_status'], self::STATUSES, true);
            $out[ $id ][ $status ] = (int) $row['n'];
            if ('inbox' === $status) {
                $out[ $id ]['unread'] = (int) $row['unread'];
            }
        }
        ksort($out);
        return $out;
    }

    /** @return array{posts: list<\WP_Post>, total: int} */
    public function entries(?int $source, string $status, int $size, int $offset): array
    {
        // Spam is not a registered post status, so WP_Query cannot filter on it; ask the posts table directly.
        $where = $this->db->prepare('post_type = %s AND post_status = %s', self::POST_TYPE, self::STATUSES[ $status ]);
        if (null !== $source) {
            $where .= $this->db->prepare(' AND post_parent = %d', $source);
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built with prepare() above.
        $total = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", $this->db->posts));
        $ids   = (array) $this->db->get_col($this->db->prepare("SELECT ID FROM %i WHERE {$where} ORDER BY post_date DESC, ID DESC LIMIT %d OFFSET %d", $this->db->posts, $size, $offset));
        // phpcs:enable
        $posts = array_values(array_filter(array_map(static fn ($id) => get_post((int) $id), $ids), static fn ($p): bool => $p instanceof \WP_Post));
        return [ 'posts' => $posts, 'total' => $total ];
    }

    /** @return array<string, mixed> The decoded JSON, or [] for a plain-text response. */
    public function content(\WP_Post $post): array
    {
        $raw     = trim((string) $post->post_content);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $decoded = json_decode(stripslashes($raw), true);
        }
        return is_array($decoded) ? $decoded : [];
    }

    public function update(int $id, ?string $post_status, ?bool $read): void
    {
        $data = [ 'ID' => $id ];
        if (null !== $post_status) {
            $data['post_status'] = $post_status;
        }
        if (null !== $read) {
            $data['comment_status'] = $read ? 'closed' : 'open';
        }
        // wp_update_post() would re-slash and re-filter the JSON body; write only the two columns.
        $done = $this->db->update($this->db->posts, array_diff_key($data, [ 'ID' => 1 ]), [ 'ID' => $id ]);
        if (false === $done) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Refused('update_failed', sprintf('Could not update Jetpack Forms response %d.', $id));
        }
        clean_post_cache($id);
    }

    public function delete(int $id): bool
    {
        return (bool) wp_delete_post($id, true);
    }
}
