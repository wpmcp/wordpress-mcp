<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * JetFormBuilder's own storage (issue #676), read in 3.6.8.2:
 *  - a form is a jet-form-builder post (Post_Type\Module::SLUG) whose
 *    content holds one jet-forms/<type>-field block per field, its `name`
 *    attribute the key its value is stored under;
 *  - a submission is a {prefix}jet_fb_records row (form_id, user_id, status
 *    of the submit, ip_address, user_agent, referrer, is_viewed,
 *    created_at) with one {prefix}jet_fb_records_fields row per value
 *    (record_id, field_name, field_value, field_type), and the action
 *    results and errors of that submit in jet_fb_records_actions and
 *    jet_fb_records_errors. The tables are created on the first saved
 *    record (Base_Db_Model::before_insert()).
 *  - a record paid through a gateway is linked from
 *    jet_fb_payments_to_records.
 *
 * Only this class touches those tables and posts.
 */
final class JetFormBuilder_Store
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes JetFormBuilder's own record tables, which have no cached read API.

    public const POST_TYPE = 'jet-form-builder';

    public const RECORDS = 'records';

    public const FIELDS = 'records_fields';

    public const PAYMENTS = 'payments_to_records';

    /** Child tables of a record, deleted before the record row. */
    public const CHILD_TABLES = [ 'records_fields', 'records_actions', 'records_errors', 'tokens_to_records', 'user_journey' ];

    /** Blocks under jet-forms/ that carry a name but store no value. */
    private const LAYOUT_BLOCKS = [ 'submit-field', 'form-break-field', 'form-break-start', 'heading-field', 'group-break-field', 'progress-bar', 'conditional-block', 'repeater-row' ];

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function table(string $name): string
    {
        return $this->db->prefix . 'jet_fb_' . $name;
    }

    public function has_tables(): bool
    {
        return Plugin_Table_Rows_Snapshot::table_exists($this->table(self::RECORDS))
            && Plugin_Table_Rows_Snapshot::table_exists($this->table(self::FIELDS));
    }

    public function form(int $form_id): ?\WP_Post
    {
        $post = get_post($form_id);
        return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type && 'trash' !== $post->post_status ? $post : null;
    }

    /** @return list<\WP_Post> */
    public function forms(?string $status): array
    {
        return array_values(get_posts([
            'post_type'        => self::POST_TYPE,
            'post_status'      => null === $status ? [ 'publish', 'draft', 'private', 'pending' ] : [ $status ],
            'posts_per_page'   => 200,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'suppress_filters' => false,
        ]));
    }

    /** @return list<JetFormBuilder_Field> */
    public function fields(\WP_Post $form): array
    {
        $out = [];
        $this->collect(parse_blocks((string) $form->post_content), $out);
        return $out;
    }

    /** @param list<JetFormBuilder_Field> $out */
    private function collect(array $blocks, array &$out): void
    {
        foreach ($blocks as $block) {
            $name  = (string) ($block['blockName'] ?? '');
            $attrs = (array) ($block['attrs'] ?? []);
            if (str_starts_with($name, 'jet-forms/')) {
                $type = substr($name, strlen('jet-forms/'));
                $key  = isset($attrs['name']) && is_scalar($attrs['name']) ? (string) $attrs['name'] : '';
                if ('' !== $key && ! in_array($type, self::LAYOUT_BLOCKS, true)) {
                    $options = [];
                    foreach ((array) ($attrs['field_options'] ?? []) as $option) {
                        if (is_array($option)) {
                            $options[] = [ 'label' => (string) ($option['label'] ?? ''), 'value' => (string) ($option['value'] ?? '') ];
                        }
                    }
                    $out[] = new JetFormBuilder_Field($key, $type, (string) ($attrs['label'] ?? ''), ! empty($attrs['required']), $options);
                }
            }
            if (! empty($block['innerBlocks'])) {
                $this->collect((array) $block['innerBlocks'], $out);
            }
        }
    }

    /**
     * Entry and unread counts per form.
     *
     * @param list<int> $form_ids
     * @return array<int, array{entries: int, unread: int}>
     */
    public function counts(array $form_ids): array
    {
        $out = array_fill_keys($form_ids, [ 'entries' => 0, 'unread' => 0 ]);
        if ([] === $form_ids || ! $this->has_tables()) {
            return $out;
        }
        $in   = implode(',', array_map('intval', $form_ids));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of intval()ed ids.
        $rows = (array) $this->db->get_results($this->db->prepare("SELECT form_id, COUNT(*) AS entries, SUM(CASE WHEN is_viewed = 1 THEN 0 ELSE 1 END) AS unread FROM %i WHERE form_id IN ({$in}) GROUP BY form_id", $this->table(self::RECORDS)), ARRAY_A);
        foreach ($rows as $row) {
            $out[ (int) $row['form_id'] ] = [ 'entries' => (int) $row['entries'], 'unread' => (int) $row['unread'] ];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function record(int $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE id = %d', $this->table(self::RECORDS), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function records(int $form_id, int $size, int $offset): array
    {
        $table = $this->table(self::RECORDS);
        $total = (int) $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM %i WHERE form_id = %d', $table, $form_id));
        $rows  = (array) $this->db->get_results($this->db->prepare('SELECT * FROM %i WHERE form_id = %d ORDER BY id DESC LIMIT %d OFFSET %d', $table, $form_id, $size, $offset), ARRAY_A);
        return [ 'rows' => array_values($rows), 'total' => $total ];
    }

    /**
     * Stored values per record, in the order they were saved.
     *
     * @param list<int> $ids
     * @return array<int, list<array{name: string, type: string, value: mixed}>>
     */
    public function values(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $in   = implode(',', array_map('intval', $ids));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of intval()ed ids.
        $rows = (array) $this->db->get_results($this->db->prepare("SELECT record_id, field_name, field_value, field_type FROM %i WHERE record_id IN ({$in}) ORDER BY id ASC", $this->table(self::FIELDS)), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $out[ (int) $row['record_id'] ][] = [ 'name' => (string) $row['field_name'], 'type' => (string) $row['field_type'], 'value' => maybe_unserialize((string) $row['field_value']) ];
        }
        return $out;
    }

    public function count_values(int $id): int
    {
        return (int) $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM %i WHERE record_id = %d', $this->table(self::FIELDS), $id));
    }

    public function has_payment(int $id): bool
    {
        $table = $this->table(self::PAYMENTS);
        return Plugin_Table_Rows_Snapshot::table_exists($table)
            && (int) $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM %i WHERE record_id = %d', $table, $id)) > 0;
    }

    public function set_viewed(int $id, bool $viewed): void
    {
        $table = $this->table(self::RECORDS);
        if (false === $this->db->update($table, [ 'is_viewed' => $viewed ? 1 : 0 ], [ 'id' => $id ])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Refused('update_failed', sprintf('Could not update JetFormBuilder record %d: %s', $id, $this->db->last_error));
        }
        Database_Guard::invalidate_caches($table, [ 'where' => [ 'id' => $id ] ]);
    }

    /**
     * The tables a record delete touches, children first and the record row
     * last, each with its primary key and the WHERE naming the record. Child
     * tables a site does not have are left out.
     *
     * @return list<array{table: string, primary_key: list<string>, where: array<string, int>}>
     */
    public function delete_plan(int $id): array
    {
        $plan = [];
        foreach (self::CHILD_TABLES as $child) {
            if (Plugin_Table_Rows_Snapshot::table_exists($this->table($child))) {
                $plan[] = [ 'table' => $this->table($child), 'primary_key' => [ 'id' ], 'where' => [ 'record_id' => $id ] ];
            }
        }
        $plan[] = [ 'table' => $this->table(self::RECORDS), 'primary_key' => [ 'id' ], 'where' => [ 'id' => $id ] ];
        return $plan;
    }

    /** Remove the rows a WHERE names; the caller holds their before-image. */
    public function delete_rows(string $table, array $where): int
    {
        $affected = $this->db->delete($table, $where);
        if (false === $affected) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Refused('delete_failed', sprintf('Could not delete rows of %s: %s', $table, $this->db->last_error));
        }
        return (int) $affected;
    }
}
