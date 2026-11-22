<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The refusals every Oxygen 6 write of issue #805 shares, each returned
 * before anything is written or snapshotted: Oxygen 6 must be active
 * (plugin_missing), a stored row is changed only against the hash it was
 * read with, the caller needs the capabilities wp-admin would ask for, and
 * imported code elements need unfiltered_html.
 */
final class Oxygen6_Guard
{
    /** Element types whose content runs or is printed as code. */
    private const CODE_ELEMENTS = ['OxygenElements\\PhpCode', 'OxygenElements\\JavaScriptCode', 'OxygenElements\\HtmlCode', 'OxygenElements\\CssCode'];

    public function unavailable(): ?\WP_Error
    {
        return Oxygen_Page::unavailable();
    }

    /** Refuse a missing or stale expected_hash for $what (a noun phrase). */
    public function hash_refusal(string $expected, string $current, string $what): ?\WP_Error
    {
        if ('' === $expected) {
            return new \WP_Error('missing_expected_hash', "expected_hash is required to change {$what}: pass the hash get-builder-content returned for it.");
        }
        if (! hash_equals($current, $expected)) {
            return new \WP_Error('stale_expected_hash', 'Stale expected_hash: it changed since it was read. Nothing was written; read it again and retry.');
        }

        return null;
    }

    public function can_edit(int $post_id): ?\WP_Error
    {
        if (current_user_can('edit_post', $post_id)) {
            return null;
        }

        return new \WP_Error('forbidden', "You are not allowed to edit post {$post_id}.", ['status' => 403]);
    }

    /**
     * Creating a post of this type, published (or private) when $publish:
     * the type's create_posts, plus publish_posts for a post that goes live.
     */
    public function can_create(string $post_type, bool $publish): ?\WP_Error
    {
        $type = get_post_type_object($post_type);
        if (null === $type) {
            return new \WP_Error('invalid_oxygen_request', "Unknown post type '{$post_type}'.");
        }
        $create = Oxygen6_Values::str(Oxygen6_Values::at($type->cap, 'create_posts'));
        if (! current_user_can($create)) {
            return new \WP_Error('forbidden', "Creating a {$post_type} needs the {$create} capability.", ['status' => 403, 'capability' => $create]);
        }
        $live = Oxygen6_Values::str(Oxygen6_Values::at($type->cap, 'publish_posts'));
        if ($publish && ! current_user_can($live)) {
            return new \WP_Error(
                'forbidden',
                "Creating a live {$post_type} needs the {$live} capability (Oxygen publishes templates, headers, footers and Components as soon as they are saved). Ask an Editor or Administrator.",
                ['status' => 403, 'capability' => $live]
            );
        }

        return null;
    }

    /**
     * Imported trees with code elements (PHP, JavaScript, HTML, CSS) run or
     * print that code on the site, so they need unfiltered_html.
     *
     * @param object[] $docs
     */
    public function code_refusal(array $docs): ?\WP_Error
    {
        if (current_user_can('unfiltered_html')) {
            return null;
        }
        foreach ($docs as $doc) {
            $root  = Oxygen6_Values::at($doc, 'root');
            $found = is_object($root) ? $this->code_element($root) : null;
            if (null !== $found) {
                return new \WP_Error('forbidden', "This import holds a {$found} element, which runs or prints code on the site; importing it needs the unfiltered_html capability.", ['status' => 403, 'capability' => 'unfiltered_html']);
            }
        }

        return null;
    }

    private function code_element(object $node): ?string
    {
        $type = Oxygen6_Values::at($node, 'data', 'type');
        if (is_string($type) && in_array($type, self::CODE_ELEMENTS, true)) {
            return $type;
        }
        foreach (Oxygen6_Values::children($node) as $child) {
            $found = $this->code_element($child);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }
}
