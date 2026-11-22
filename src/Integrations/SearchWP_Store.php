<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * SearchWP 4's own storage (issue #993):
 *  - the engines config, the searchwp_engines option: engine name =>
 *    label, settings and sources, each source (post.post, post.page, ...)
 *    with its attributes (attribute => weight), rules and options;
 *  - the index queue, {prefix}searchwp_status: one row per entry and
 *    source, with the time it was queued, indexed or omitted;
 *  - the index itself, {prefix}searchwp_index (token, occurrences, id,
 *    attribute, source);
 *  - the search log, {prefix}searchwp_log (query, engine, tstamp, hits).
 *
 * Only this class touches them.
 */
final class SearchWP_Store
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live counts of SearchWP's own index tables, which have no cached read API.

    public const ENGINES_OPTION = 'searchwp_engines';

    public const LOG = 'searchwp_log';

    private const STATUS = 'searchwp_status';

    private const INDEX = 'searchwp_index';

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function table(string $name): string
    {
        return $this->db->prefix . $name;
    }

    /** @return array<string, array<string, mixed>> */
    public function engines(): array
    {
        $engines = get_option(self::ENGINES_OPTION, []);
        return is_array($engines) ? $engines : [];
    }

    /** @param array<string, array<string, mixed>> $engines */
    public function save_engines(array $engines): void
    {
        update_option(self::ENGINES_OPTION, $engines);
    }

    /** @return array{entries: int, indexed: int, queued: int, omitted: int, by_source: array<string, int>, index_rows: int|null, status_table: bool} */
    public function index_counts(): array
    {
        $status = $this->table(self::STATUS);
        $out    = [ 'entries' => 0, 'indexed' => 0, 'queued' => 0, 'omitted' => 0, 'by_source' => [], 'index_rows' => null, 'status_table' => Ops_Status_Packs::table_exists($status) ];
        if ($out['status_table']) {
            $row = (array) $this->db->get_row($this->db->prepare('SELECT COUNT(*) AS entries, COUNT(indexed) AS indexed, SUM(CASE WHEN indexed IS NULL AND omitted IS NULL AND queued IS NOT NULL THEN 1 ELSE 0 END) AS queued, COUNT(omitted) AS omitted FROM %i', $status), ARRAY_A);
            foreach ([ 'entries', 'indexed', 'queued', 'omitted' ] as $key) {
                $out[ $key ] = (int) ($row[ $key ] ?? 0);
            }
            foreach ((array) $this->db->get_results($this->db->prepare('SELECT source, COUNT(*) AS n FROM %i GROUP BY source ORDER BY source', $status), ARRAY_A) as $source) {
                $out['by_source'][ (string) $source['source'] ] = (int) $source['n'];
            }
        }
        $index = $this->table(self::INDEX);
        if (Ops_Status_Packs::table_exists($index)) {
            $out['index_rows'] = (int) $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM %i', $index));
        }
        return $out;
    }
}
