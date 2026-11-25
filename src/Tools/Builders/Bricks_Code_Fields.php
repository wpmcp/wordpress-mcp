<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Code_Fields;
use WPMCP\Safety\Code_Values;
use WPMCP\Safety\Decoded_Tree;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where a Bricks layout keeps code, recorded from Bricks 2.3.9: what its
 * own save (Helpers::security_check_elements_before_save()) leaves to users
 * allowed to run code, and the function calls interactions make.
 *
 * - A code element set to run (settings.executeCode): the PHP and HTML it
 *   evaluates (code), the CSS and JavaScript it prints in style and script
 *   tags (cssCode, javascriptCode) and the dynamic data it may use instead
 *   (useDynamicData). A code element that only shows its code prints it
 *   escaped, as text.
 * - A query editor (settings.query.queryEditor), PHP Bricks runs while
 *   useQueryEditor is on.
 * - {echo:...} dynamic data tags, which call PHP functions, in any value.
 * - Interactions with the javascript action: the global function they call
 *   and the arguments they pass it.
 * - Custom attributes (settings._attributes): each name and value is
 *   printed on the element as an attribute, so they are judged as markup.
 */
final class Bricks_Code_Fields implements Code_Fields
{
    /** The values of a running code element. */
    private const CODE_ELEMENT_KEYS = ['code', 'cssCode', 'javascriptCode', 'useDynamicData'];

    /** @return list<string> */
    public function code(mixed $value): array
    {
        $out = [];
        foreach (Decoded_Tree::nodes($value) as $node) {
            array_push($out, ...$this->element_code($node), ...$this->interaction_code($node));
        }
        foreach (Decoded_Tree::strings($value) as $string) {
            if (false === strpos($string, '{echo:') || 0 === (int) preg_match_all('/\{echo:([^\}]+)\}/', $string, $tags)) {
                continue;
            }
            foreach ($tags[1] as $tag) {
                $out[] = Code_Values::of('bricks echo tag', $tag);
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function markup(mixed $value): array
    {
        $out = [];
        foreach (Decoded_Tree::nodes($value) as $node) {
            $attributes = Decoded_Tree::fields($node['settings'] ?? null)['_attributes'] ?? null;
            if (! is_string($node['name'] ?? null) || ! is_array($attributes)) {
                continue;
            }
            foreach ($attributes as $attribute) {
                $attribute = Decoded_Tree::fields($attribute);
                $name      = $attribute['name'] ?? null;
                if (! is_string($name) || '' === $name) {
                    continue;
                }
                $text  = $attribute['value'] ?? '';
                $out[] = Code_Values::attribute($name, is_scalar($text) ? (string) $text : '');
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $node
     * @return list<string>
     */
    private function element_code(array $node): array
    {
        $settings = $node['settings'] ?? null;
        if (! is_string($node['name'] ?? null) || ! is_array($settings)) {
            return [];
        }
        $out = [];
        if ('code' === $node['name'] && isset($settings['executeCode'])) {
            foreach (self::CODE_ELEMENT_KEYS as $key) {
                $code = $settings[ $key ] ?? null;
                if (is_string($code) && '' !== $code) {
                    $out[] = Code_Values::of("bricks code element {$key}", $code);
                }
            }
        }
        $query  = is_array($settings['query'] ?? null) ? $settings['query'] : [];
        $editor = $query['queryEditor'] ?? null;
        if (is_string($editor) && '' !== $editor) {
            $out[] = Code_Values::of(isset($query['useQueryEditor']) ? 'bricks query editor (on)' : 'bricks query editor', $editor);
        }

        return $out;
    }

    /**
     * @param array<mixed> $node
     * @return list<string>
     */
    private function interaction_code(array $node): array
    {
        $function = $node['jsFunction'] ?? null;
        if ('javascript' !== ($node['action'] ?? null) || ! is_string($function) || '' === $function) {
            return [];
        }
        $arguments = is_array($node['jsFunctionArgs'] ?? null) ? $node['jsFunctionArgs'] : [];

        return [Code_Values::of('bricks interaction call', (string) wp_json_encode([$function, Decoded_Tree::strings($arguments)]))];
    }
}
