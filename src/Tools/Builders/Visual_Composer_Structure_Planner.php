<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Plans Visual Composer structural writes (issue #1112) and validates them
 * against both stores before anything is written.
 *
 * The server cannot render a Visual Composer element (its markup and CSS
 * come from each element's React component in the browser editor), so a
 * write here only moves or removes markup the editor already rendered:
 *
 * - remove cuts the element's whole rendered markup out of its container;
 * - move cuts it and splices it into the target container at the index;
 * - a blank page takes a saved template whole: its document, its markup
 *   and its compiled CSS, ids unchanged (they are unique per page).
 *
 * The compiled CSS is keyed by element id (#el-<id>), so it still applies
 * after a move; after a remove it holds rules for an id no longer on the
 * page, which match nothing. A container is only touched when its saved
 * markup holds exactly the children its document lists, in order, so the
 * two stores stay in step. Anything else, and every change the editor lays
 * out (a column, which its row lays out; an element dropped at the top,
 * which the editor wraps in a row and column; a new element; a template
 * into a page that has a layout, which needs new ids and merged CSS), is a
 * \DomainException naming it, for editor_render_required. Caller mistakes
 * are \InvalidArgumentException.
 */
final class Visual_Composer_Structure_Planner
{
    public function __construct(
        private readonly Visual_Composer_Markup_Parser $parser,
        private readonly Visual_Composer_Element_Rules $rules,
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \DomainException
     */
    public function remove(object $document, string $html, string $id): Visual_Composer_Structure_Plan
    {
        $doc     = $this->copy($document);
        $element = $this->element($doc, $id);
        $this->movable($element, 'removing');
        $parent = $this->parent_of($element);

        $markup = $this->markup($html);
        $this->in_step($doc, $markup, $parent);
        $outer   = $markup->outer($id);
        $subtree = $this->subtree($doc, $id);
        if (null === $outer || [] !== array_diff($markup->element_ids($outer), $subtree)) {
            throw new \DomainException(esc_html("removing '{$id}' (its saved markup holds elements its document does not list)"));
        }
        $new_html = substr($html, 0, $outer->start) . substr($html, $outer->end);

        $elements = $this->elements($doc);
        foreach ($subtree as $gone) {
            unset($elements->{$gone});
        }
        $this->renumber($doc, $this->children($doc, $parent));

        $this->in_step($doc, $this->markup($new_html), $parent);

        return new Visual_Composer_Structure_Plan($doc, $new_html, [], [], $id);
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \DomainException
     */
    public function move(object $document, string $html, string $id, string $to, ?int $index): Visual_Composer_Structure_Plan
    {
        $doc     = $this->copy($document);
        $element = $this->element($doc, $id);
        $rule    = $this->movable($element, 'moving');
        $from    = $this->parent_of($element);

        if ('' === $to) {
            $target = $this->rules->root();
            $label  = 'the top of the page';
        } else {
            $target_element = $this->element($doc, $to, 'to');
            if (in_array($to, $this->subtree($doc, $id), true)) {
                throw new \InvalidArgumentException(esc_html("'{$id}' cannot move into itself or one of its own elements."));
            }
            $target = $this->rule_of($target_element);
            $label  = "a {$target->tag}";
            if ([] === $target->container_for) {
                throw new \InvalidArgumentException(esc_html("'{$to}' is a {$target->tag}, which holds no elements."));
            }
        }
        if (! $target->holds($rule)) {
            if (in_array('General', $rule->related_to, true)) {
                throw new \DomainException(esc_html("moving a {$rule->tag} into {$label} (the editor wraps it in a row and column there)"));
            }
            throw new \InvalidArgumentException(esc_html("Visual Composer does not put a {$rule->tag} into {$label}."));
        }

        $siblings = array_values(array_diff($this->children($doc, $to), [$id]));
        $index    = $index ?? count($siblings);
        if ($index < 0 || $index > count($siblings)) {
            throw new \InvalidArgumentException(esc_html('index must be between 0 and ' . count($siblings) . '.'));
        }

        $markup = $this->markup($html);
        $this->in_step($doc, $markup, $from);
        $this->in_step($doc, $markup, $to);
        $outer = $markup->outer($id);
        if (null === $outer) {
            throw new \DomainException(esc_html("moving '{$id}' (its saved markup could not be found)"));
        }
        $piece = $markup->slice($outer);
        $cut   = substr($html, 0, $outer->start) . substr($html, $outer->end);

        $offset = $this->markup($cut)->insert_offset($to, $index);
        if (null === $offset) {
            throw new \DomainException(esc_html("moving '{$id}' (the saved markup of its new container could not be read)"));
        }
        $new_html = substr($cut, 0, $offset) . $piece . substr($cut, $offset);

        $element->parent = '' === $to ? false : $to;
        array_splice($siblings, $index, 0, [$id]);
        $this->renumber($doc, $siblings);
        if ($from !== $to) {
            $this->renumber($doc, $this->children($doc, $from));
        }

        $after = $this->markup($new_html);
        $this->in_step($doc, $after, $from);
        $this->in_step($doc, $after, $to);

        return new Visual_Composer_Structure_Plan($doc, $new_html, [], [], $id);
    }

    /**
     * A blank page made from a saved template, taken whole.
     *
     * @throws \InvalidArgumentException
     * @throws \DomainException
     */
    public function from_template(Visual_Composer_Template $template): Visual_Composer_Structure_Plan
    {
        $source = $template->document;
        if (null === $source || ! isset($source->elements) || ! is_object($source->elements) || [] === get_object_vars($source->elements)) {
            throw new \InvalidArgumentException(esc_html("Template {$template->id} holds no Visual Composer elements."));
        }
        $doc = $this->copy($source);
        if ('' === trim($template->html)) {
            throw new \DomainException(esc_html("template {$template->id} (it is stored as a document only, with no rendered markup)"));
        }
        $markup = $this->markup($template->html);
        $this->in_step($doc, $markup, '');
        foreach (array_keys(get_object_vars($this->elements($doc))) as $id) {
            $id = (string) $id;
            if (null === $markup->outer($id)) {
                throw new \DomainException(esc_html("template {$template->id} (its saved markup does not hold element '{$id}')"));
            }
            if ([] !== $this->children($doc, $id)) {
                $this->in_step($doc, $markup, $id);
            }
        }
        $base = $template->css_meta[ Visual_Composer_Page_Store::ELEMENTS_CSS_KEY ] ?? null;
        if (! is_array($base) || [] === $base) {
            throw new \DomainException(esc_html("template {$template->id} (its elements' CSS was never compiled by the editor)"));
        }

        return new Visual_Composer_Structure_Plan(
            $doc,
            $template->html,
            $template->css_meta + [Visual_Composer_Content::EDITOR_META_KEY => 'fe'],
            Visual_Composer_Page_Store::CSS_FILE_KEYS
        );
    }

    private function markup(string $html): Visual_Composer_Markup
    {
        try {
            return $this->parser->parse($html);
        } catch (\DomainException $e) {
            throw new \DomainException(esc_html('this page (its saved markup could not be read: ' . $e->getMessage() . ')'));
        }
    }

    /** A deep copy that keeps empty objects as objects. */
    private function copy(object $document): \stdClass
    {
        $doc = json_decode((string) wp_json_encode($document));
        if (! $doc instanceof \stdClass) {
            throw new \InvalidArgumentException(esc_html('This page holds no Visual Composer document that can be read.'));
        }
        $this->elements($doc);

        return $doc;
    }

    /** The document's elements, keyed by id. */
    private function elements(\stdClass $doc): \stdClass
    {
        $elements = $doc->elements ?? null;
        if (! $elements instanceof \stdClass) {
            throw new \InvalidArgumentException(esc_html('This page holds no Visual Composer document that can be read.'));
        }

        return $elements;
    }

    private function element(\stdClass $doc, string $id, string $field = 'path'): \stdClass
    {
        $element = '' === $id ? null : ($this->elements($doc)->{$id} ?? null);
        if (! $element instanceof \stdClass) {
            throw new \InvalidArgumentException(esc_html("{$field}: no element with id '{$id}' on this page; read it for the ids."));
        }

        return $element;
    }

    private function rule_of(\stdClass $element): Visual_Composer_Element_Rule
    {
        $tag  = is_string($element->tag ?? null) ? $element->tag : '';
        $rule = $this->rules->for_tag($tag);
        if (null === $rule) {
            throw new \DomainException(esc_html("this {$tag} element (its rules could not be read from an installed Visual Composer element)"));
        }

        return $rule;
    }

    private function movable(\stdClass $element, string $doing): Visual_Composer_Element_Rule
    {
        $rule = $this->rule_of($element);
        if ('' !== $rule->parent_wrapper) {
            throw new \DomainException(esc_html("{$doing} a {$rule->tag} (its {$rule->parent_wrapper} lays it out)"));
        }

        return $rule;
    }

    /** The parent id of an element, '' at the top of the page. */
    private function parent_of(\stdClass $element): string
    {
        $parent = $element->parent ?? false;
        if (false === $parent || ! is_scalar($parent)) {
            return '';
        }

        return (string) $parent;
    }

    /**
     * The ids of a container's children in document order.
     *
     * @return list<string>
     */
    private function children(\stdClass $doc, string $parent): array
    {
        $ids = [];
        $pos = 0;
        foreach (get_object_vars($this->elements($doc)) as $id => $element) {
            if ($element instanceof \stdClass && $this->parent_of($element) === $parent) {
                $order = $element->order ?? 0;
                $ids[] = [(string) $id, is_scalar($order) ? (int) $order : 0, $pos++];
            }
        }
        usort($ids, static fn (array $a, array $b): int => [$a[1], $a[2]] <=> [$b[1], $b[2]]);

        return array_column($ids, 0);
    }

    /**
     * The element and every element inside it.
     *
     * @return list<string>
     */
    private function subtree(\stdClass $doc, string $id): array
    {
        $out  = [$id];
        $todo = [$id];
        while ([] !== $todo) {
            foreach ($this->children($doc, array_shift($todo)) as $child) {
                if (! in_array($child, $out, true)) {
                    $out[]  = $child;
                    $todo[] = $child;
                }
            }
        }

        return $out;
    }

    /** @param list<string> $ids */
    private function renumber(\stdClass $doc, array $ids): void
    {
        foreach ($ids as $order => $id) {
            $this->element($doc, $id)->order = $order;
        }
    }

    /** Refuses a container whose saved markup does not hold exactly its document's children, in order. */
    private function in_step(\stdClass $doc, Visual_Composer_Markup $markup, string $parent): void
    {
        $nodes = $markup->children_of($parent);
        $label = '' === $parent ? 'the top of this page' : "element '{$parent}'";
        if (null === $nodes) {
            throw new \DomainException(esc_html("this change (the saved markup of {$label} could not be read)"));
        }
        $expected = $this->children($doc, $parent);
        $actual   = array_map(static fn (Visual_Composer_Markup_Node $n): ?string => $markup->element_id($n), $nodes);
        if ($expected !== $actual) {
            throw new \DomainException(esc_html("this change (the saved markup of {$label} is out of step with the editor's document; open and save the page in Visual Composer first)"));
        }
        foreach ($expected as $i => $id) {
            $outer = $markup->outer($id);
            if (null === $outer || $outer->start !== $nodes[ $i ]->start) {
                throw new \DomainException(esc_html("this change (the saved markup of element '{$id}' could not be told apart)"));
            }
        }
    }
}
