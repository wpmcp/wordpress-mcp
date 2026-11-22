<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Depicter 4's document storage for the writes of issue #1091, written as
 * DocumentRepository writes it: the document row of depicter_documents
 * (parent 0), its revision rows (parent = the document; a publish adds one,
 * an unpublish sets them all to draft), and its display rules, the JSON
 * `rules` row of depicter_meta (relation document).
 *
 * Only this class touches those tables.
 */
final class Depicter_Write_Store
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Depicter's own tables, written as its DocumentRepository writes them; it keeps no object cache of them.

    public const DOCUMENTS = 'depicter_documents';

    public const META = 'depicter_meta';

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function table(string $name): string
    {
        return $this->db->prefix . $name;
    }

    /** @return array<string, mixed>|null The document row, never a revision. */
    public function document(int $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE id = %d AND parent = 0', $this->table(self::DOCUMENTS), $id), ARRAY_A);
        return is_array($row) ? Typed::map($row) : null;
    }

    private function now(): string
    {
        return current_time('mysql');
    }

    public function rename(int $id, string $name): void
    {
        $this->update(self::DOCUMENTS, [ 'name' => $name, 'modified_at' => $this->now() ], [ 'id' => $id ]);
    }

    public function set_status(int $id, string $status): void
    {
        $this->update(self::DOCUMENTS, [ 'status' => $status, 'modified_at' => $this->now() ], [ 'id' => $id ]);
    }

    /** Set every revision of a document to draft, as an unpublish does. */
    public function draft_revisions(int $id): void
    {
        $this->update(self::DOCUMENTS, [ 'status' => 'draft' ], [ 'parent' => $id ]);
    }

    /** Add the published checkpoint a publish adds: a copy of the document under it. */
    public function add_revision(int $id): void
    {
        $doc = $this->document($id);
        if (null === $doc) {
            return;
        }
        $row = array_intersect_key($doc, array_flip([ 'name', 'slug', 'type', 'author', 'sections_count', 'thumbnail', 'content', 'password' ]));
        $row = array_map([ Typed::class, 'str' ], $row) + [ 'created_at' => $this->now(), 'modified_at' => $this->now(), 'status' => 'publish', 'parent' => (string) $id ];
        if (false === $this->db->insert($this->table(self::DOCUMENTS), $row)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Refused('update_failed', sprintf('Could not add a published revision of Depicter document %d.', $id));
        }
    }

    public function rules(int $id): ?string
    {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a column of Depicter's own meta table.
        $raw = $this->db->get_var($this->db->prepare("SELECT meta_value FROM %i WHERE relation = 'document' AND relation_id = %d AND meta_key = 'rules' LIMIT 1", $this->table(self::META), $id));
        return is_string($raw) ? $raw : null;
    }

    /** @param array<string, mixed> $rules */
    public function save_rules(int $id, array $rules): void
    {
        $json = (string) wp_json_encode($rules);
        if (null === $this->rules($id)) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- columns of Depicter's own meta table.
            $done = $this->db->insert($this->table(self::META), [ 'relation' => 'document', 'relation_id' => $id, 'meta_key' => 'rules', 'meta_value' => $json ]);
        } else {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- columns of Depicter's own meta table.
            $done = $this->db->update($this->table(self::META), [ 'meta_value' => $json ], [ 'relation' => 'document', 'relation_id' => $id, 'meta_key' => 'rules' ]);
        }
        if (false === $done) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Refused('update_failed', sprintf('Could not save the display rules of Depicter document %d.', $id));
        }
    }

    /**
     * @param array<string, int|string> $set
     * @param array<string, int|string> $where
     */
    private function update(string $name, array $set, array $where): void
    {
        if (false === $this->db->update($this->table($name), $set, $where)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Refused('update_failed', 'Could not update the Depicter document.');
        }
    }
}
