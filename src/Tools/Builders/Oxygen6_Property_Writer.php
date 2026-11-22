<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * update-builder-content operation set_properties (issue #805): set the
 * editable property values of one Oxygen 6 Component instance on a page.
 * path is the instance node id, attrs maps property keys to values. Pinned
 * to the page's layout hash and undone through its post snapshot.
 */
final class Oxygen6_Property_Writer
{
    public function __construct(
        private readonly Oxygen6_Store $store,
        private readonly Oxygen6_Snapshots $snapshots,
        private readonly Oxygen6_Guard $guard,
        private readonly Oxygen6_Component_Properties $properties
    ) {
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public function set(array $args): array|\WP_Error
    {
        $post_id = Oxygen6_Values::int($args['post_id'] ?? null);
        $doc     = null !== $this->store->post($post_id) ? $this->store->document($post_id) : null;
        if (null === $doc) {
            Oxygen6_Request_Error::raise('set_properties needs post_id: a page holding an Oxygen layout.');
        }
        $refusal = $this->guard->can_edit($post_id)
            ?? $this->guard->hash_refusal(Oxygen6_Values::str($args['expected_hash'] ?? null), $this->store->layout_hash($post_id), 'an Oxygen page');
        if (null !== $refusal) {
            return $refusal;
        }

        $path  = Oxygen6_Values::str($args['path'] ?? null);
        $attrs = $args['attrs'] ?? null;
        if (! is_array($attrs)) {
            Oxygen6_Request_Error::raise('set_properties needs attrs: {propertyKey: value} (null removes a value).');
        }
        $component_id = $this->properties->component_id(Breakdance_Tree::node($doc, $path));
        $component    = $this->component($component_id, $path);
        [$doc, $values] = $this->properties->apply($doc, $path, Oxygen6_Values::map($attrs), $component);

        $operation_id = $this->snapshots->write_post($post_id, $args, function () use ($post_id, $doc): void {
            $this->store->save_document($post_id, $doc);
        });

        return [
            'operation_id' => $operation_id,
            'post_id'      => $post_id,
            'builder'      => 'oxygen',
            'path'         => $path,
            'hash'         => $this->store->layout_hash($post_id),
            'properties'   => (object) $values,
        ];
    }

    private function component(int $component_id, string $path): object
    {
        if (0 === $component_id) {
            Oxygen6_Request_Error::raise("Node {$path} is not a Component instance (an OxygenElements\\Component node).");
        }
        $post = $this->store->post($component_id);
        $doc  = null !== $post && Oxygen6_Store::KINDS['block'] === $post->post_type ? $this->store->document($component_id) : null;
        if (null === $doc) {
            Oxygen6_Request_Error::raise("Node {$path} places Component {$component_id}, which is not an Oxygen Component on this site.");
        }

        return $doc;
    }
}
