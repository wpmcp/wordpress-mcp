<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Kadence Blocks advanced forms' own storage (issue #676), read in 3.9:
 * a form is a kadence_form post with its settings in the _kad_form_* post
 * meta that includes/advanced-form/advanced-form-cpt.php registers, and
 * Kadence_Blocks_Advanced_Form_Ajax reads at submit time. Free Kadence
 * Blocks stores no submissions.
 *
 * Only this class reads or writes those posts and meta.
 */
final class Kadence_Forms_Store
{
    public const POST_TYPE = 'kadence_form';

    public const ACTIONS = '_kad_form_actions';

    /** notification id => the meta key it lives in. */
    public const NOTIFICATIONS = [ 'email' => '_kad_form_email', 'autoEmail' => '_kad_form_autoEmail' ];

    public function form(int $id): ?\WP_Post
    {
        $post = get_post($id);
        return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type && 'trash' !== $post->post_status ? $post : null;
    }

    /** @return list<\WP_Post> */
    public function forms(?string $status): array
    {
        return array_values(get_posts([
            'post_type'        => self::POST_TYPE,
            'post_status'      => null === $status ? [ 'publish', 'draft', 'private', 'pending' ] : [ $status ],
            'posts_per_page'   => 200,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'suppress_filters' => false,
        ]));
    }

    /** @return array<mixed> */
    public function meta_array(int $id, string $key): array
    {
        $value = get_post_meta($id, $key, true);
        return is_array($value) ? $value : [];
    }

    public function meta_string(int $id, string $key): string
    {
        $value = get_post_meta($id, $key, true);
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> */
    public function actions(int $id): array
    {
        return array_values(array_map('strval', array_filter($this->meta_array($id, self::ACTIONS), 'is_scalar')));
    }

    /** @param list<string> $actions */
    public function save_actions(int $id, array $actions): void
    {
        update_post_meta($id, self::ACTIONS, array_values(array_unique($actions)));
    }

    /** @param array<string, mixed> $settings */
    public function save_notification(int $id, string $notification, array $settings): void
    {
        update_post_meta($id, self::NOTIFICATIONS[ $notification ], $settings);
    }
}
