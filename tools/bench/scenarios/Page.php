<?php

declare(strict_types=1);

namespace WPMCP\Bench\Scenarios;

/**
 * Fixture posts for the scenarios' seed() (issue #1130): wp_insert_post()
 * that fails loudly, so a seed never hands the steps an id of 0.
 * REPO-ONLY, like everything under tools/.
 */
final class Page
{
    /**
     * @param array{post_type: string, post_status: string, post_title: string, post_content?: string} $post
     */
    public static function create(array $post): int
    {
        $id = wp_insert_post($post, true);
        if (! is_int($id) || 0 === $id) {
            throw new \RuntimeException('could not create the fixture ' . $post['post_title']);
        }
        return $id;
    }
}
