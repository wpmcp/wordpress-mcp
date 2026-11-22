<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A parsed Visual Composer page markup (issue #1112): post_content and its
 * element nodes, with the lookups a structural write needs.
 *
 * Recorded from Visual Composer 45.16.4: each element's component renders
 * its outermost node (where the editor's props go) as a direct child of the
 * parent's data-vce-element-content node, or of the page at the top, and
 * puts id="el-<id>" on that node or one inside it (row:
 * vce-row-container > vce-row#el; column: vce-col#el; Text Block:
 * vce-text-block > vce-text-block-wrapper#el; Basic Button:
 * vce-button--style-basic-container > span#el). So an element's whole
 * rendered markup is the direct child of its container that holds its
 * el-<id> node, whatever the element type.
 */
final class Visual_Composer_Markup
{
    /** @param list<Visual_Composer_Markup_Node> $roots the element nodes at the top of the page */
    public function __construct(
        public readonly string $html,
        public readonly int $root_start,
        public readonly int $root_end,
        public readonly array $roots,
        public readonly bool $root_text,
    ) {
    }

    /** @return list<Visual_Composer_Markup_Node> */
    public function root_children(): array
    {
        return $this->roots;
    }

    public function slice(Visual_Composer_Markup_Node $node): string
    {
        return substr($this->html, $node->start, $node->end - $node->start);
    }

    /** The element id a container child renders: the first el-<id> in it, in document order. */
    public function element_id(Visual_Composer_Markup_Node $node): ?string
    {
        if (null !== $node->el_id) {
            return $node->el_id;
        }
        foreach ($node->children as $child) {
            $id = $this->element_id($child);
            if (null !== $id) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Every el-<id> in the subtree, in document order.
     *
     * @return list<string>
     */
    public function element_ids(Visual_Composer_Markup_Node $node): array
    {
        $ids = null !== $node->el_id ? [$node->el_id] : [];
        foreach ($node->children as $child) {
            array_push($ids, ...$this->element_ids($child));
        }

        return $ids;
    }

    /**
     * The nodes from the top of the page down to the one el-<id> node, or
     * null unless exactly one node carries it.
     *
     * @return list<Visual_Composer_Markup_Node>|null
     */
    public function path(string $id): ?array
    {
        $found = [];
        $this->collect($this->roots, $id, [], $found);

        return 1 === count($found) ? $found[0] : null;
    }

    /**
     * The element's outermost node: the ancestor-or-self of its el-<id>
     * node that sits directly in a container's content node or at the top,
     * with no other element's el- node in between.
     */
    public function outer(string $id): ?Visual_Composer_Markup_Node
    {
        $path = $this->path($id);
        if (null === $path) {
            return null;
        }
        for ($i = count($path) - 1; $i >= 0; --$i) {
            if ($i < count($path) - 1 && null !== $path[ $i ]->el_id) {
                return null;
            }
            if (0 === $i || $path[ $i - 1 ]->content) {
                return $path[ $i ];
            }
        }

        return null;
    }

    /**
     * The one data-vce-element-content node of a container element, or
     * null when it has none (it holds no elements) or more than one.
     */
    public function content(string $id): ?Visual_Composer_Markup_Node
    {
        $outer = $this->outer($id);
        if (null === $outer) {
            return null;
        }
        $found = [];
        $this->contents($outer, $id, $found);

        return 1 === count($found) ? $found[0] : null;
    }

    /**
     * The element nodes directly in a container ('' is the page), or null
     * when the container has no content node or text sits loose in it.
     *
     * @return list<Visual_Composer_Markup_Node>|null
     */
    public function children_of(string $parent): ?array
    {
        if ('' === $parent) {
            return $this->root_text ? null : $this->roots;
        }
        $content = $this->content($parent);
        if (null === $content || $content->loose_text) {
            return null;
        }

        return $content->children;
    }

    /** The offset a child inserted at $index of a container goes in, or null. */
    public function insert_offset(string $parent, int $index): ?int
    {
        $children = $this->children_of($parent);
        if (null === $children || $index < 0 || $index > count($children)) {
            return null;
        }
        if ($index < count($children)) {
            return $children[ $index ]->start;
        }
        if ('' === $parent) {
            return $this->root_end;
        }
        $content = $this->content($parent);

        return null === $content ? null : $content->inner_end;
    }

    /**
     * @param list<Visual_Composer_Markup_Node>        $nodes
     * @param list<Visual_Composer_Markup_Node>        $trail
     * @param list<list<Visual_Composer_Markup_Node>> $found
     */
    private function collect(array $nodes, string $id, array $trail, array &$found): void
    {
        foreach ($nodes as $node) {
            $here = [...$trail, $node];
            if ($node->el_id === $id) {
                $found[] = $here;
            }
            $this->collect($node->children, $id, $here, $found);
        }
    }

    /** @param list<Visual_Composer_Markup_Node> $found */
    private function contents(Visual_Composer_Markup_Node $node, string $id, array &$found): void
    {
        foreach ($node->children as $child) {
            if (null !== $child->el_id && $child->el_id !== $id) {
                continue;
            }
            if ($child->content) {
                $found[] = $child;
                continue;
            }
            $this->contents($child, $id, $found);
        }
    }
}
