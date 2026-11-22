<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * get-builder-content scope export (issue #805): one Oxygen 6 page, post,
 * template, header, footer, Component or part as an Oxygen6_Library_Package,
 * with every Component it places (and the Components those place) and the
 * site's variables and selectors, as Oxygen's design library serves them.
 */
final class Oxygen6_Library_Exporter
{
    public function __construct(private readonly Oxygen6_Store $store)
    {
    }

    /** @return array<string,mixed>|\WP_Error */
    public function export(int $post_id): array|\WP_Error
    {
        $missing = 'export needs post_id: a post holding an Oxygen layout.';
        $post    = $this->store->post($post_id);
        if (null === $post) {
            Oxygen6_Request_Error::raise($missing);
        }
        $row = $this->store->data_row($post_id);
        $doc = $this->store->document($post_id);
        if (null === $row || null === $doc) {
            Oxygen6_Request_Error::raise($missing);
        }
        if (! current_user_can('edit_post', $post_id)) {
            return new \WP_Error('forbidden', "You are not allowed to export post {$post_id}.", ['status' => 403]);
        }

        $item = null === $this->store->kind_of($post->post_type)
            ? [
                'postData'       => [
                    'post_title'   => $post->post_title,
                    'post_name'    => $post->post_name,
                    'post_content' => $post->post_content,
                    'post_excerpt' => $post->post_excerpt,
                    'post_status'  => $post->post_status,
                    'post_type'    => $post->post_type,
                ],
                'breakdanceData' => $row,
            ]
            : $this->template_item($post);

        $design  = $this->store->design();
        $package = new Oxygen6_Library_Package(
            $item,
            $this->components($doc, $post_id),
            $design['variables'],
            $design['selectors'],
            home_url(),
            Oxygen_Page::plugin_version()
        );

        return [
            'builder' => 'oxygen',
            'scope'   => 'export',
            'post_id' => $post_id,
            'hash'    => $this->store->layout_hash($post_id),
            'package' => $package->to_array(),
        ];
    }

    /** @return array<string,mixed> getTemplateData()'s shape */
    private function template_item(\WP_Post $post): array
    {
        return [
            'title'          => $post->post_title,
            'settings'       => $this->store->settings($post->ID),
            'postType'       => $post->post_type,
            'breakdanceData' => $this->store->data_row($post->ID),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function components(object $doc, int $self): array
    {
        $out   = [];
        $seen  = [$self => true];
        $queue = $this->referenced($doc);
        while ([] !== $queue) {
            $id = array_shift($queue);
            if (isset($seen[ $id ])) {
                continue;
            }
            $seen[ $id ] = true;
            $post = $this->store->post($id);
            $tree = null !== $post && Oxygen6_Store::KINDS['block'] === $post->post_type ? $this->store->document($id) : null;
            if (null === $post || null === $tree) {
                continue;
            }
            $out[] = ['id' => $id] + $this->template_item($post);
            $queue = array_merge($queue, $this->referenced($tree));
        }

        return $out;
    }

    /** @return int[] the Component ids a tree places */
    private function referenced(object $doc): array
    {
        $root = Oxygen6_Values::at($doc, 'root');
        $ids  = [];
        if (is_object($root)) {
            $this->collect_ids($root, $ids);
        }

        return $ids;
    }

    /** @param int[] $ids */
    private function collect_ids(object $node, array &$ids): void
    {
        $id = Oxygen6_Values::at($node, 'data', 'properties', 'content', 'content', 'block', 'componentId');
        if (Oxygen_Page::COMPONENT_TYPE === Oxygen6_Values::at($node, 'data', 'type') && is_int($id)) {
            $ids[] = $id;
        }
        foreach (Oxygen6_Values::children($node) as $child) {
            $this->collect_ids($child, $ids);
        }
    }
}
