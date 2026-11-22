<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Storage of the Cart Abandonment Recovery ops (issue #1096), as Cart
 * Abandonment Recovery for WooCommerce (woo-cart-abandonment-recovery,
 * 2.2.x) keeps it: carts in cartflows_ca_cart_abandonment (order_status
 * normal, abandoned, completed (recovered) or lost), follow-up emails in
 * cartflows_ca_email_templates (frequency and frequency_unit are the delay)
 * with their coupon settings in cartflows_ca_email_templates_meta, the send
 * queue in cartflows_ca_email_history, settings in wcf_ca_* options read the
 * way the plugin reads them (an empty value means the default).
 */
final class Cart_Abandonment_Store
{
    public const CARTS = 'cartflows_ca_cart_abandonment';

    public const TEMPLATES = 'cartflows_ca_email_templates';

    public const TEMPLATE_META = 'cartflows_ca_email_templates_meta';

    public const HISTORY = 'cartflows_ca_email_history';

    public const STATUSES = [ 'normal', 'abandoned', 'completed', 'lost' ];

    public const UNITS = [ 'MINUTE' => 1, 'HOUR' => 60, 'DAY' => 1440 ];

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function table(string $name): string
    {
        return $this->db->prefix . $name;
    }

    public function has_tables(): bool
    {
        foreach ([ self::CARTS, self::TEMPLATES, self::HISTORY ] as $name) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a schema check of the plugin's own tables.
            if ($this->table($name) !== $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $this->db->esc_like($this->table($name))))) {
                return false;
            }
        }
        return true;
    }

    /**
     * An option as the plugin's wcar_get_option() reads it: an unset or
     * empty value is the default.
     *
     */
    public function option(string $name, mixed $default): mixed
    {
        $value = get_option($name);
        return in_array($value, [ false, null, '', 0, '0', [] ], true) ? $default : $value;
    }

    /** @return list<array<string, mixed>> */
    public function templates(): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; it has no API that returns its rows.
        return array_map([ Typed::class, 'map' ], Typed::list($this->db->get_results($this->db->prepare('SELECT * FROM %i ORDER BY id', $this->table(self::TEMPLATES)), ARRAY_A)));
    }

    /** @return array<string, mixed>|null */
    public function template(int $id): ?array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE id = %d', $this->table(self::TEMPLATES), $id), ARRAY_A);
        return is_array($row) ? Typed::map($row) : null;
    }

    /** @return array<string, mixed> meta_key => value */
    public function template_meta(int $id): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        $rows = (array) $this->db->get_results($this->db->prepare('SELECT meta_key, meta_value FROM %i WHERE email_template_id = %d', $this->table(self::TEMPLATE_META), $id), ARRAY_A);
        $meta = [];
        foreach ($rows as $row) {
            $meta[ Typed::str($row['meta_key'] ?? null) ] = self::plain(Typed::str($row['meta_value'] ?? null));
        }
        return $meta;
    }

    /** @return array{sent: int, queued: int} The template's rows of the send queue. */
    public function sends(int $template_id): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        $row = $this->db->get_row($this->db->prepare('SELECT SUM(email_sent = 1) AS sent, SUM(email_sent = 0) AS queued FROM %i WHERE template_id = %d', $this->table(self::HISTORY), $template_id), ARRAY_A);
        $row = Typed::map($row);
        return [ 'sent' => Typed::int($row['sent'] ?? null), 'queued' => Typed::int($row['queued'] ?? null) ];
    }

    /** @return array{orders: int, revenue: float} Carts of one status whose date falls in the range, as the plugin's reports count them. */
    public function report(string $status, string $from, string $to): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the same query as the plugin's get_report_by_type().
        $row = $this->db->get_row($this->db->prepare('SELECT SUM(cart_total) AS revenue, COUNT(*) AS orders FROM %i WHERE order_status = %s AND DATE(`time`) >= %s AND DATE(`time`) <= %s', $this->table(self::CARTS), $status, $from, $to), ARRAY_A);
        $row = Typed::map($row);
        return [ 'orders' => Typed::int($row['orders'] ?? null), 'revenue' => round(Typed::float($row['revenue'] ?? null), 2) ];
    }

    /** @return array{total: int, rows: list<array<string, mixed>>} */
    public function carts(?string $status, int $limit, int $offset): array
    {
        $table = $this->table(self::CARTS);
        if (null === $status) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
            $total = (int) $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM %i', $table));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
            $rows = $this->db->get_results($this->db->prepare('SELECT id, email, cart_contents, cart_total, session_id, order_status, unsubscribed, `time` FROM %i ORDER BY `time` DESC, id DESC LIMIT %d OFFSET %d', $table, $limit, $offset), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
            $total = (int) $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM %i WHERE order_status = %s', $table, $status));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
            $rows = $this->db->get_results($this->db->prepare('SELECT id, email, cart_contents, cart_total, session_id, order_status, unsubscribed, `time` FROM %i WHERE order_status = %s ORDER BY `time` DESC, id DESC LIMIT %d OFFSET %d', $table, $status, $limit, $offset), ARRAY_A);
        }
        return [ 'total' => $total, 'rows' => array_map([ Typed::class, 'map' ], Typed::list($rows)) ];
    }

    /** Emails sent for one cart session. */
    public function sent_for(string $session): int
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        return (int) $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM %i WHERE ca_session_id = %s AND email_sent = 1', $this->table(self::HISTORY), $session));
    }

    /** @param array<string, int|string> $set Columns of the template row. */
    public function update_template(int $id, array $set): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin saves templates with the same direct update; it has no API for it.
        if (false === $this->db->update($this->table(self::TEMPLATES), $set, [ 'id' => $id ])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Refused('template_update_failed', sprintf('Template %d could not be saved.', $id));
        }
    }

    /**
     * A stored value unserialized without objects (the plugin writes arrays
     * and scalars only).
     *
     */
    public static function plain(string $value): mixed
    {
        if (! is_serialized($value)) {
            return $value;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- objects refused, so a crafted row cannot instantiate anything.
        $out = unserialize($value, [ 'allowed_classes' => false ]);
        return false === $out && 'b:0;' !== $value ? null : $out;
    }
}
