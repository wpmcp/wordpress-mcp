<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Adapter;

use WPMCP\Api\V1\Attribute\Provides;
use WPMCP\Modules\Cmb2\Value\Cmb2_Box_Spec;
use WPMCP\Modules\Cmb2\Value\Cmb2_Field_Spec;
use WPMCP\Modules\Cmb2\Value\Cmb2_Scalar;
use WPMCP\Modules\Cmb2\Value\Cmb2_Secret_Policy;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The CMB2 boxes registered in code (issue #1089), read from
 * CMB2_Boxes::get_all() after the admin-like context has registered the
 * cmb2_admin_init boxes, and turned into Cmb2_Box_Spec value objects.
 *
 * Filters: wpmcp_cmb2_active (whether CMB2 is loaded) and wpmcp_cmb2_boxes
 * (the CMB2 objects keyed by box id), so tests and sites can hand boxes in.
 */
#[Provides(Cmb2_Boxes::class)]
final class Wp_Cmb2_Boxes implements Cmb2_Boxes
{
    /** Object types that are not post types. */
    private const NON_POST_TYPES = [ 'options-page', 'user', 'term', 'comment' ];

    /** @var array<string, Cmb2_Box_Spec>|null */
    private ?array $boxes = null;

    public function __construct(
        private readonly Cmb2_Admin_Context $context,
        private readonly Cmb2_Secret_Policy $secrets
    ) {
    }

    /** Whether CMB2 is loaded, as a plugin or bundled in a theme. */
    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_cmb2_active', class_exists('CMB2_Boxes') || class_exists('CMB2'));
    }

    /** Whether the cmb2_admin_init boxes are in the list. */
    public function admin_boxes_loaded(): bool
    {
        return $this->context->loaded();
    }

    /** @return array<string, Cmb2_Box_Spec> every box, by id, in registration order. */
    public function all(): array
    {
        if (null === $this->boxes) {
            $this->context->ensure(fn (): array => array_keys($this->natives()));
            $boxes = [];
            foreach ($this->natives() as $id => $native) {
                $boxes[ $id ] = $this->spec($id, $native);
            }
            $this->boxes = $boxes;
        }
        return $this->boxes;
    }

    public function get(string $id): ?Cmb2_Box_Spec
    {
        return $this->all()[ $id ] ?? null;
    }

    /** @return array<string, object> the CMB2 objects, by box id. */
    private function natives(): array
    {
        $all = [];
        if (class_exists('CMB2_Boxes') && is_callable([ 'CMB2_Boxes', 'get_all' ])) {
            $all = (array) \CMB2_Boxes::get_all();
        }
        /** Filter: the CMB2 boxes (CMB2 objects) keyed by box id. */
        $all = (array) apply_filters('wpmcp_cmb2_boxes', $all);

        $out = [];
        foreach ($all as $native) {
            if (! is_object($native) || ! method_exists($native, 'prop')) {
                continue;
            }
            $id = Cmb2_Scalar::text($native->prop('id'));
            if ('' !== $id) {
                $out[ $id ] = $native;
            }
        }
        return $out;
    }

    private function spec(string $id, object $native): Cmb2_Box_Spec
    {
        $types = Cmb2_Scalar::strings(method_exists($native, 'box_types') ? $native->box_types() : $this->prop($native, 'object_types'));
        $keys  = [];
        $kind  = '';
        if (method_exists($native, 'is_options_page_mb') && $native->is_options_page_mb()) {
            $kind = Cmb2_Box_Spec::OPTIONS_PAGE;
            $keys = method_exists($native, 'options_page_keys') ? array_values(array_filter(Cmb2_Scalar::strings($native->options_page_keys()), static fn (string $key): bool => '' !== $key)) : [];
        }
        $post_types = array_values(array_diff($types, self::NON_POST_TYPES));
        if ('' === $kind) {
            $kind = [] !== $post_types ? Cmb2_Box_Spec::POST : array_values(array_intersect([ 'user', 'term', 'comment' ], $types))[0] ?? Cmb2_Box_Spec::POST;
        }
        if (Cmb2_Box_Spec::POST === $kind && [] === $post_types) {
            // CMB2 falls back to the post object type for a box with no types.
            $post_types = [ 'post' ];
        }

        $fields = [];
        foreach ((array) $this->prop($native, 'fields') as $config) {
            $config = Cmb2_Scalar::map($config);
            $field_id = Cmb2_Scalar::text($config['id'] ?? '');
            if ('' !== $field_id && 'title' !== ($config['type'] ?? '')) {
                $fields[ $field_id ] = $this->field($config);
            }
        }

        $title = $this->prop($native, 'title');
        $capability = Cmb2_Scalar::text($this->prop($native, 'capability'));
        return new Cmb2_Box_Spec(
            $id,
            is_string($title) ? wp_strip_all_tags($title) : '',
            $kind,
            Cmb2_Box_Spec::POST === $kind ? $post_types : [],
            $keys,
            '' !== $capability && '0' !== $capability ? $capability : 'manage_options',
            'network_admin_menu' === $this->prop($native, 'admin_menu_hook'),
            $fields,
            $this->context->origin($id),
            $native
        );
    }

    /** @param array<string, mixed> $config */
    private function field(array $config): Cmb2_Field_Spec
    {
        $subs = [];
        if ('group' === ($config['type'] ?? '')) {
            foreach ((array) ($config['fields'] ?? []) as $sub) {
                $sub = Cmb2_Scalar::map($sub);
                $sub_id = Cmb2_Scalar::text($sub['id'] ?? '');
                if ('' !== $sub_id) {
                    $subs[ $sub_id ] = $this->field($sub);
                }
            }
        }
        $options = [];
        if (is_array($config['options'] ?? null)) {
            foreach ($config['options'] as $key => $label) {
                $options[ (string) $key ] = is_scalar($label) ? wp_strip_all_tags((string) $label) : (string) $key;
            }
        }
        $default = $config['default'] ?? null;
        $name    = $config['name'] ?? '';

        return new Cmb2_Field_Spec(
            Cmb2_Scalar::text($config['id'] ?? ''),
            Cmb2_Scalar::text($config['type'] ?? ''),
            is_string($name) ? wp_strip_all_tags($name) : '',
            is_callable($default) && ! is_string($default) ? null : $default,
            $options,
            (bool) ($config['options_cb'] ?? false) || (isset($config['options']) && ! is_array($config['options'])),
            (bool) ($config['repeatable'] ?? false),
            (bool) ($config['multiple'] ?? false),
            ! array_key_exists('save_field', $config) || (bool) $config['save_field'],
            $this->secrets->is_secret($config),
            $subs
        );
    }

    /** A CMB2 box property, through the box's own prop() (natives() keeps only boxes that have it). */
    private function prop(object $native, string $key): mixed
    {
        return method_exists($native, 'prop') ? $native->prop($key) : null;
    }
}
