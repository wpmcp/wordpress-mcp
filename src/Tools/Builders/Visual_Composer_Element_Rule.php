<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where a Visual Composer element type may sit (issue #1112), as its own
 * settings declare it (Visual Composer 45.16.4 element bundles): the groups
 * it belongs to (relatedTo), the groups it holds (containerFor) and the
 * element that lays it out (parentWrapper, "row" for a column).
 */
final class Visual_Composer_Element_Rule
{
    /**
     * @param list<string> $related_to
     * @param list<string> $container_for
     */
    public function __construct(
        public readonly string $tag,
        public readonly array $related_to,
        public readonly array $container_for,
        public readonly string $parent_wrapper,
    ) {
    }

    /** Whether an element of $child may sit directly in this one. */
    public function holds(self $child): bool
    {
        return [] !== array_intersect($child->related_to, $this->container_for);
    }
}
