<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * update-builder-content's structural operations for Visual Composer
 * (issue #1112): remove (path: element id), move (path, to: container id
 * or '' for the top, index) and add with element {template: id} on a blank
 * page. Every other add is refused with editor_render_required.
 *
 * Checks, in order, before anything is written: a Visual Composer page (or
 * a blank one for a template), Visual Composer loaded (plugin_missing),
 * expected_hash on a page that has a layout, then the plan (see
 * Visual_Composer_Structure_Planner). Only a complete plan is written, under
 * the post snapshot (see Visual_Composer_Structure_Snapshot).
 */
final class Visual_Composer_Structure_Write
{
    public const OPERATIONS = ['add', 'remove', 'move'];

    public function __construct(
        private readonly Visual_Composer_Page_Store $store,
        private readonly Visual_Composer_Structure_Planner $planner,
        private readonly Visual_Composer_Structure_Snapshot $snapshot,
    ) {
    }

    public static function create(): self
    {
        return new self(
            new Visual_Composer_Page_Store(),
            new Visual_Composer_Structure_Planner(new Visual_Composer_Markup_Parser(), new Visual_Composer_Element_Rules()),
            new Visual_Composer_Structure_Snapshot()
        );
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public function handle(int $post_id, array $args): array|\WP_Error
    {
        $operation = $this->text($args, 'operation');
        $detected  = Builder_Detector::detect($post_id);
        $blank     = 'visual-composer' !== $detected && 'add' === $operation && $this->store->is_blank($post_id);
        if ('visual-composer' !== $detected && ! $blank) {
            return new \WP_Error('unsupported_builder', "This post was detected as '{$detected}', not a Visual Composer page.");
        }
        if (! Visual_Composer_Content::plugin_active()) {
            return Visual_Composer_Content::unavailable();
        }
        $expected = $this->text($args, 'expected_hash');
        if ('' === $expected && ! $blank) {
            return new \WP_Error('missing_expected_hash', 'expected_hash is required to change a Visual Composer page: pass the hash get-builder-content returned for it.');
        }
        if ('' !== $expected && ! hash_equals($this->store->hash($post_id), $expected)) {
            return new \WP_Error('stale_expected_hash', 'Stale expected_hash: the page changed since it was read. Nothing was written; read it again and retry.');
        }

        try {
            $plan = $this->plan($post_id, $operation, $args, $blank);
        } catch (\DomainException $e) {
            return Visual_Composer_Content::render_required($post_id, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_visual_composer_request', $e->getMessage());
        }
        if ($plan instanceof \WP_Error) {
            return $plan;
        }
        $refusal = Visual_Composer_Content::unfiltered_html_refusal($post_id, $plan->html);
        if (null !== $refusal) {
            return $refusal;
        }

        $operation_id = $this->snapshot->run($post_id, $args, fn () => $this->store->save($post_id, $plan));

        $result = [
            'operation_id' => $operation_id,
            'post_id'      => $post_id,
            'builder'      => 'visual-composer',
            'hash'         => $this->store->hash($post_id),
        ];
        if (null !== $plan->path) {
            $result['path'] = $plan->path;
        }

        return $result;
    }

    /** @param array<string,mixed> $args */
    private function plan(int $post_id, string $operation, array $args, bool $blank): Visual_Composer_Structure_Plan|\WP_Error
    {
        $path = $this->text($args, 'path');
        if ('remove' === $operation) {
            return $this->planner->remove($this->document($post_id), $this->store->html($post_id), $path);
        }
        if ('move' === $operation) {
            $index = isset($args['index']) && is_numeric($args['index']) ? (int) $args['index'] : null;

            return $this->planner->move($this->document($post_id), $this->store->html($post_id), $path, $this->text($args, 'to'), $index);
        }

        $element = is_array($args['element'] ?? null) ? $args['element'] : [];
        if (! array_key_exists('template', $element)) {
            throw new \DomainException(esc_html("a new element (its markup and CSS come from the element's component in the editor; add it there, or fill a blank page with element {template: id})"));
        }
        if (! $blank) {
            throw new \DomainException(esc_html('a template into a page that has a layout (the editor gives the inserted elements new ids and compiles their CSS); fill a blank page with it instead'));
        }
        $template_id = is_numeric($element['template']) ? (int) $element['template'] : 0;
        $template    = $template_id > 0 ? get_post($template_id) : null;
        if (! $template instanceof \WP_Post || Visual_Composer_Content::TEMPLATE_POST_TYPE !== $template->post_type) {
            throw new \InvalidArgumentException(esc_html('element.template must be the id of a Visual Composer template (get-builder-content scope templates lists them).'));
        }
        if (! current_user_can('read_post', $template_id)) {
            return new \WP_Error('forbidden', 'You are not allowed to read this template.', ['status' => 403]);
        }

        return $this->planner->from_template($this->store->template($template_id));
    }

    /**
     * A string argument of the tool call, '' when it is missing or not a scalar.
     *
     * @param array<string,mixed> $args
     */
    private function text(array $args, string $key): string
    {
        $value = $args[ $key ] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function document(int $post_id): object
    {
        $doc = $this->store->document($post_id);
        if (null === $doc) {
            throw new \InvalidArgumentException(esc_html('This page holds no Visual Composer document that can be read.'));
        }

        return $doc;
    }
}
