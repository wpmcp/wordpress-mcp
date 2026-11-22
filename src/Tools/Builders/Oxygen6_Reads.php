<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registration glue for the Oxygen 6 reads of issue #805 on
 * get-builder-content (builder oxygen):
 *
 * - scope properties, post_id: a Component: the editable properties it
 *   declares (what set_properties takes);
 * - scope conditions: Oxygen's template types and templating conditions
 *   (what save_template and set_conditions settings are checked against)
 *   and, with post_id of a template, header or footer, its settings and the
 *   hash set_conditions is pinned to;
 * - scope export, post_id: the design library package import takes.
 */
final class Oxygen6_Reads
{
    public const SCOPES = ['properties', 'conditions', 'export'];

    public function __construct(
        private readonly Oxygen6_Store $store,
        private readonly Oxygen6_Component_Properties $properties,
        private readonly Oxygen6_Vocabulary_Source $vocabulary,
        private readonly Oxygen6_Library_Exporter $exporter
    ) {
    }

    public static function standard(): self
    {
        $store = new Oxygen6_Store();

        return new self($store, new Oxygen6_Component_Properties(), new Oxygen6_Vocabulary_Source(), new Oxygen6_Library_Exporter($store));
    }

    /** @param array<string,mixed> $args */
    public static function handles(array $args): bool
    {
        return 'oxygen' === ($args['builder'] ?? '') && in_array(Oxygen6_Values::str($args['scope'] ?? null), self::SCOPES, true);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public function read(array $args): array|\WP_Error
    {
        $post_id = Oxygen6_Values::int($args['post_id'] ?? null);
        try {
            switch (Oxygen6_Values::str($args['scope'] ?? null)) {
                case 'properties':
                    return $this->properties($post_id);
                case 'conditions':
                    return $this->conditions($post_id);
                default:
                    return $this->exporter->export($post_id);
            }
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_oxygen_request', $e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function properties(int $post_id): array
    {
        $post = $this->store->post($post_id);
        $doc  = null !== $post && Oxygen6_Store::KINDS['block'] === $post->post_type ? $this->store->document($post_id) : null;
        if (null === $doc) {
            Oxygen6_Request_Error::raise('scope properties needs post_id: an Oxygen Component (get-builder-content scope templates, type block).');
        }

        return [
            'builder'      => 'oxygen',
            'scope'        => 'properties',
            'component_id' => $post_id,
            'properties'   => array_map(static fn (Oxygen6_Component_Property $p): array => $p->to_array(), $this->properties->declared($doc)),
            'hash'         => $this->store->layout_hash($post_id),
        ];
    }

    /** @return array<string,mixed>|\WP_Error */
    private function conditions(int $post_id): array|\WP_Error
    {
        $vocabulary = $this->vocabulary->load();
        if (null === $vocabulary) {
            return Oxygen6_Vocabulary_Source::not_loaded();
        }
        $out = ['builder' => 'oxygen', 'scope' => 'conditions', 'plugin_active' => Breakdance_Cache::plugin_active('oxygen')] + $vocabulary->to_array();
        if ($post_id > 0) {
            $post = $this->store->post($post_id);
            $kind = null !== $post ? $this->store->kind_of($post->post_type) : null;
            if (null === $kind || ! in_array($kind, Oxygen6_Store::CONDITION_KINDS, true)) {
                Oxygen6_Request_Error::raise('scope conditions takes post_id of an Oxygen template, header or footer.');
            }
            $out += [
                'template_id' => $post_id,
                'kind'        => $kind,
                'settings'    => $this->store->settings($post_id),
                'hash'        => $this->store->settings_hash($post_id),
            ];
        }

        return $out;
    }
}
