<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The SearchWP ops (issue #993) for the theme pair, next to Relevanssi and
 * FiboSearch (#700):
 *  - get-searchwp-settings: each engine with its label, settings and sources
 *    (attribute weights);
 *  - get-searchwp-index-status: entries queued, indexed and omitted, per
 *    source, and the index row count;
 *  - list-searchwp-top-searches: its search log aggregated per query and
 *    masked (Search_Plugin_Support::top_searches);
 *  - rebuild-searchwp-index: SearchWP's own index reset and rebuild, derived
 *    data, so not snapshotted (recoverable:false), and confirm:true first;
 *  - update-searchwp-engine: attribute weights (1 to 100) of one source of
 *    one engine, only attributes the source already has, under an
 *    option_set snapshot of searchwp_engines (rollback-operation), off until
 *    wpmcp_enable_search_write.
 * Every op runs at manage_options, SearchWP's settings capability.
 */
final class SearchWP_Operations
{
    private const MIN_WEIGHT = 1;

    private const MAX_WEIGHT = 100;

    /**
     * @param \Closure(): (true|array<string, mixed>) $presence
     * @param \Closure(): mixed                      $rebuild  The indexer reset callable, or null.
     */
    public function __construct(
        private readonly SearchWP_Store $store,
        private readonly \Closure $presence,
        private readonly \Closure $rebuild
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $requires = $this->presence;

        return [
            'get-searchwp-settings'      => Ops_Status_Packs::read_op(
                'SearchWP engines: each engine\'s label, settings and sources with their attribute weights',
                $requires,
                fn (): array => [ 'engines' => $this->engines() ]
            ),
            'get-searchwp-index-status'  => Ops_Status_Packs::read_op(
                'SearchWP index status: entries queued, indexed and omitted, per source, and the index row count',
                $requires,
                fn (): array => $this->store->index_counts() + [ 'rebuild_supported' => is_callable(($this->rebuild)()) ]
            ),
            'list-searchwp-top-searches' => Ops_Status_Packs::read_op(
                'SearchWP top searches from its search log, aggregated per query (count, most results, last time), personal data masked; zero_hits:true for searches that found nothing',
                $requires,
                fn (array $args): array => Search_Plugin_Support::top_searches($this->store->table(SearchWP_Store::LOG), 'query', 'hits', 'tstamp', $args),
                Search_Plugin_Support::top_searches_schema()
            ),
            'rebuild-searchwp-index'     => [
                'mode'            => 'destructive',
                'capability'      => 'manage_options',
                'description'     => 'Rebuild the SearchWP index with its own indexer (empties and rebuilds it in the background). Derived data: not snapshotted, not reversible',
                'input_schema'    => [ 'type' => 'object', 'properties' => [] ],
                'requires'        => $requires,
                'validate'        => fn (): ?array => is_callable(($this->rebuild)()) ? null : [
                    'code'    => 'searchwp_index_unavailable',
                    'message' => 'SearchWP is active but its indexer is not available.',
                ],
                'confirm_preview' => fn (): array => [
                    'message' => sprintf('This empties the SearchWP index (%d indexed entries) and rebuilds it in the background.', $this->store->index_counts()['indexed']),
                    'data'    => [ 'indexed' => $this->store->index_counts()['indexed'] ],
                ],
                'handler'         => function (): array {
                    call_user_func(($this->rebuild)());
                    return [ 'rebuild_started' => true ];
                },
            ],
            'update-searchwp-engine'     => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'description'        => 'Set attribute weights (1 to 100) of one source of a SearchWP engine: engine, source (e.g. post.post), attributes {name: weight}, names as get-searchwp-settings reports them. Snapshotted; rollback-operation restores them. Disabled by default (wpmcp_enable_search_write filter)',
                'enabled_by_default' => Search_Plugin_Support::writes_enabled(),
                'opt_in_filter'      => Search_Plugin_Support::ENABLE_FILTER,
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'engine'     => [ 'type' => 'string', 'pattern' => '^[a-z0-9_-]{1,60}$' ],
                        'source'     => [ 'type' => 'string', 'pattern' => '^[a-z0-9_.-]{1,100}$' ],
                        'attributes' => [ 'type' => 'object', 'minProperties' => 1, 'maxProperties' => 50 ],
                    ],
                    'required'   => [ 'engine', 'source', 'attributes' ],
                ],
                'requires'           => $requires,
                'validate'           => fn (array $args): ?array => $this->engine_refusal($args),
                'snapshot'           => static fn (): array => [
                    'object_type' => 'option_set',
                    'object_id'   => Snapshot::option_set_id([ SearchWP_Store::ENGINES_OPTION ]),
                ],
                'handler'            => fn (array $args): array => $this->set_weights($args),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function engines(): array
    {
        $out = [];
        foreach ($this->store->engines() as $name => $engine) {
            $sources = [];
            foreach ((array) ($engine['sources'] ?? []) as $source => $config) {
                $sources[] = [ 'name' => (string) $source, 'attributes' => (array) ($config['attributes'] ?? []) ];
            }
            $out[] = [
                'name'     => (string) $name,
                'label'    => (string) ($engine['label'] ?? $name),
                'settings' => (array) ($engine['settings'] ?? []),
                'sources'  => $sources,
            ];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function engine_refusal(array $args): ?array
    {
        $engines = $this->store->engines();
        $engine  = (string) $args['engine'];
        $source  = (string) $args['source'];
        if (! isset($engines[ $engine ]) || ! is_array($engines[ $engine ])) {
            return [ 'code' => 'searchwp_engine_not_found', 'message' => sprintf('SearchWP has no engine "%s". Engines: %s.', $engine, implode(', ', array_keys($engines))), 'data' => [ 'engine' => $engine ] ];
        }
        $sources = (array) ($engines[ $engine ]['sources'] ?? []);
        if (! isset($sources[ $source ]) || ! is_array($sources[ $source ])) {
            return [ 'code' => 'searchwp_source_not_found', 'message' => sprintf('Engine "%s" does not search "%s". Sources: %s.', $engine, $source, implode(', ', array_keys($sources))), 'data' => [ 'engine' => $engine, 'source' => $source ] ];
        }
        $known = (array) ($sources[ $source ]['attributes'] ?? []);
        foreach ((array) $args['attributes'] as $name => $weight) {
            $name = (string) $name;
            if (! array_key_exists($name, $known)) {
                return Search_Plugin_Support::invalid(sprintf('"%s" is not an attribute of %s in this engine; add it in SearchWP first. Attributes: %s.', $name, $source, implode(', ', array_keys($known))), $name);
            }
            if (! is_int($weight) || $weight < self::MIN_WEIGHT || $weight > self::MAX_WEIGHT) {
                return Search_Plugin_Support::invalid(sprintf('The weight of "%s" must be a whole number from %d to %d.', $name, self::MIN_WEIGHT, self::MAX_WEIGHT), $name);
            }
        }
        return null;
    }

    /** @return array{engine: string, source: string, attributes: array<string, mixed>, previous: array<string, mixed>} */
    private function set_weights(array $args): array
    {
        $engines  = $this->store->engines();
        $engine   = (string) $args['engine'];
        $source   = (string) $args['source'];
        $previous = (array) $engines[ $engine ]['sources'][ $source ]['attributes'];
        $changed  = array_intersect_key($previous, (array) $args['attributes']);
        foreach ((array) $args['attributes'] as $name => $weight) {
            $engines[ $engine ]['sources'][ $source ]['attributes'][ (string) $name ] = (int) $weight;
        }
        $this->store->save_engines($engines);

        return [ 'engine' => $engine, 'source' => $source, 'attributes' => (array) $engines[ $engine ]['sources'][ $source ]['attributes'], 'previous' => $changed ];
    }
}
