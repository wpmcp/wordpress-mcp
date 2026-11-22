<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * update-builder-content operation create_page (issue #805): a new post
 * with an Oxygen 6 layout, element {title, post_type? (page), status?
 * (draft)} and, optionally, content: a JSON array of starting nodes. The
 * layout starts from Oxygen_Page::blank(), the document Oxygen's editor
 * starts from. Recorded as a creation, so rollback trashes the post.
 */
final class Oxygen6_Page_Creator
{
    private const STATUSES = ['draft', 'pending', 'private', 'publish'];

    public function __construct(
        private readonly Oxygen6_Store $store,
        private readonly Oxygen6_Snapshots $snapshots,
        private readonly Oxygen6_Guard $guard
    ) {
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public function create(array $args): array|\WP_Error
    {
        $element = is_array($args['element'] ?? null) ? $args['element'] : [];
        $title   = is_string($element['title'] ?? null) ? trim($element['title']) : '';
        if ('' === $title) {
            Oxygen6_Request_Error::raise('create_page needs element {title, post_type?, status?}.');
        }
        $type   = Oxygen6_Values::str($element['post_type'] ?? 'page');
        $status = Oxygen6_Values::str($element['status'] ?? 'draft');
        if (null !== $this->store->kind_of($type)) {
            Oxygen6_Request_Error::raise("create_page makes pages and posts; save Oxygen templates, headers, footers and Components with save_template.");
        }
        if (! post_type_exists($type) || 'attachment' === $type || ! is_post_type_viewable($type)) {
            Oxygen6_Request_Error::raise("create_page needs a public post type; got '{$type}'.");
        }
        if (! in_array($status, self::STATUSES, true)) {
            Oxygen6_Request_Error::raise("status must be draft, pending, private or publish; got '{$status}'.");
        }

        $doc     = Oxygen_Page::blank();
        $content = Oxygen6_Values::str($args['content'] ?? null);
        if ('' !== $content) {
            $list = json_decode($content);
            if (! is_array($list)) {
                Oxygen6_Request_Error::raise('content must be a JSON array of nodes {type, properties?, children?}.');
            }
            $doc = Breakdance_Tree::replace($doc, $list);
        }

        $refusal = $this->guard->can_create($type, in_array($status, ['publish', 'private'], true));
        if (null !== $refusal) {
            return $refusal;
        }

        $post_id = $this->store->insert_post(['post_title' => sanitize_text_field($title), 'post_type' => $type, 'post_status' => $status]);
        try {
            $this->store->save_document($post_id, $doc);
            $operation_id = $this->snapshots->record_creation([$post_id], $args);
        } catch (\Throwable $e) {
            $this->store->remove_created([$post_id]);
            throw $e;
        }

        return ['operation_id' => $operation_id, 'post_id' => $post_id, 'builder' => 'oxygen', 'hash' => $this->store->layout_hash($post_id)];
    }
}
