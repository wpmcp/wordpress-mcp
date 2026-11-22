<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One pixelyoursite-set-settings call (#1094), read from the op's JSON args
 * (already checked against its input schema).
 */
final class PixelYourSite_Change
{
    /**
     * @param list<string>|null    $ids
     * @param array<string, bool> $events
     */
    public function __construct(
        public readonly PixelYourSite_Network $network,
        public readonly ?array $ids,
        public readonly ?bool $enabled,
        public readonly array $events
    ) {
    }

    /** @param array<mixed> $args the op's arguments, keyed by name */
    public static function from_args(array $args): self
    {
        $network = PixelYourSite_Network::from(is_string($args['network'] ?? null) ? $args['network'] : '');
        $ids     = null;
        if (is_array($args['ids'] ?? null)) {
            $ids = [];
            foreach ($args['ids'] as $id) {
                $ids[] = trim(is_scalar($id) ? (string) $id : '');
            }
            $ids = array_values(array_unique($ids));
        }
        $events = [];
        foreach (is_array($args['events'] ?? null) ? $args['events'] : [] as $event => $on) {
            $events[ (string) $event ] = true === $on;
        }
        return new self($network, $ids, is_bool($args['enabled'] ?? null) ? $args['enabled'] : null, $events);
    }

    /**
     * The module settings after this change.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function apply(array $settings): array
    {
        if (null !== $this->ids) {
            $settings[ $this->network->id_key() ] = $this->ids;
        }
        if (null !== $this->enabled) {
            $settings['enabled'] = $this->enabled;
        }
        foreach ($this->events as $event => $on) {
            $settings[ $event ] = $on;
        }
        return $settings;
    }

    /** @return list<string> */
    public function changed(): array
    {
        $out = [];
        if (null !== $this->ids) {
            $out[] = $this->network->id_key();
        }
        if (null !== $this->enabled) {
            $out[] = 'enabled';
        }
        return array_merge($out, array_keys($this->events));
    }
}
