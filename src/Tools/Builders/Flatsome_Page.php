<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * update-builder-content for a Flatsome (UX Builder) page or UX Block: the
 * whole shortcode content, or one element operation addressed by the dotted
 * paths get-builder-content returns (update, add, remove, move), add with
 * element {block: <id>} to place a UX Block, and save_template to save an
 * element or the whole page as a new UX Block (see Flatsome_Blocks).
 *
 * Every refusal is decided before anything is written or snapshotted:
 * Flatsome must be the active theme (plugin_missing otherwise), the post
 * must be a Flatsome layout or blank, and a post holding a layout is changed
 * only against the hash it was read with, since paths are child indexes and
 * a stale path could land on another element. Page writes then run through
 * Safe_Mutation::run() with the post snapshot, which restores post_content
 * and every postmeta row on rollback.
 */
final class Flatsome_Page
{
    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function write(int $post_id, array $args)
    {
        if (! Flatsome_Content::theme_active()) {
            return Flatsome_Content::unavailable();
        }

        $operation = (string) ($args['operation'] ?? '');
        $content   = $args['content'] ?? null;
        $current   = Flatsome_Content::get_content($post_id);

        if ('' === $operation && ! is_string($content)) {
            return new \WP_Error('invalid_flatsome_content', 'Flatsome content must be a shortcode string, or pass an operation.');
        }

        $detected = Builder_Detector::detect($post_id);
        $blank    = 'classic' === $detected && '' === trim($current);
        if ('flatsome' !== $detected && ! $blank) {
            return new \WP_Error('unsupported_builder', "This post was detected as '{$detected}', not a Flatsome page or UX Block.");
        }

        if ('' !== trim($current)) {
            $expected = (string) ($args['expected_hash'] ?? '');
            if ('' === $expected) {
                return new \WP_Error('missing_expected_hash', 'expected_hash is required to change a Flatsome page: pass the hash get-builder-content returned for it.');
            }
            if (! hash_equals(Flatsome_Content::hash($post_id), $expected)) {
                return new \WP_Error('stale_expected_hash', 'Stale expected_hash: the page changed since it was read. Nothing was written; read it again and retry.');
            }
        }

        if ('save_template' === $operation) {
            return Flatsome_Blocks::save_template($post_id, $args);
        }

        $path = null;
        if ('' !== $operation) {
            try {
                [$content, $path] = self::apply($current, $operation, $args);
            } catch (\InvalidArgumentException $e) {
                return new \WP_Error('invalid_flatsome_operation', $e->getMessage());
            }
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-builder-content',
                'args'        => $args,
            ],
            static function () use ($post_id, $content): bool {
                Flatsome_Content::save($post_id, (string) $content);
                return true;
            }
        );

        $result = ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'builder' => 'flatsome', 'hash' => Flatsome_Content::hash($post_id)];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * The new content for one element operation, and the path it names
     * when it has one.
     *
     * @param array<string,mixed> $args
     * @return array{0:string,1:?string}
     * @throws \InvalidArgumentException
     */
    private static function apply(string $content, string $operation, array $args): array
    {
        $path  = (string) ($args['path'] ?? '');
        $to    = (string) ($args['to'] ?? '');
        $index = isset($args['index']) ? (int) $args['index'] : null;

        switch ($operation) {
            case 'update':
                $attrs = $args['attrs'] ?? null;
                $text  = $args['text'] ?? null;
                if (null !== $attrs && ! is_array($attrs)) {
                    throw new \InvalidArgumentException(esc_html('attrs must be an object.'));
                }
                if (null !== $text && ! is_string($text)) {
                    throw new \InvalidArgumentException(esc_html('text must be a string.'));
                }
                return [Flatsome_Shortcodes::update($content, $path, $attrs, $text), $path];

            case 'add':
                $element = $args['element'] ?? null;
                if (! is_array($element)) {
                    throw new \InvalidArgumentException(esc_html('add needs an element object: {tag, attrs?, text? | children?} or {block: <id>}.'));
                }
                if (array_key_exists('block', $element)) {
                    if (1 !== count($element)) {
                        throw new \InvalidArgumentException(esc_html('element {block} takes only a UX Block id.'));
                    }
                    return Flatsome_Blocks::insert($content, $to, $index, $element['block']);
                }
                return Flatsome_Shortcodes::add($content, $to, $index, $element);

            case 'remove':
                return [Flatsome_Shortcodes::remove($content, $path), null];

            case 'move':
                return [Flatsome_Shortcodes::move($content, $path, $to, $index), null];
        }

        throw new \InvalidArgumentException(esc_html("Unknown operation {$operation}; use update, add, remove, move or save_template."));
    }
}
