<?php

/**
 * Stand-ins for CMB2 boxes (issue #1089), recorded from CMB2 2.13.5
 * (includes/CMB2.php, CMB2_Field.php, CMB2_Sanitize.php and
 * CMB2_Options.php): a box carries its id, its config (prop()), the object
 * types it shows on, the option keys of an options page box, and hands out
 * field objects whose save_field() runs the type's sanitizer and stores the
 * value in post meta, or in the option array of an options page, where an
 * empty value removes the field as CMB2 does.
 *
 * The doubles are plain objects in this namespace, handed to the pack
 * through the wpmcp_cmb2_boxes filter, so no global CMB2 class is defined
 * and no other test sees CMB2 as active. CMB2 keeps an options page's values
 * in memory until CMB2_Options::set(); the double writes them straight away.
 */

namespace WPMCP\Tests\Support;

final class Cmb2_Box_Double
{
    /** @var string */
    public $cmb_id;

    /** @var array<string, mixed> */
    private $meta_box;

    /** @var int|string */
    private $object_id = 0;

    /** @var string */
    private $object_type = '';

    /** @var int how often save_field() ran on one of this box's fields */
    public $saves = 0;

    /**
     * @param array<string, mixed>             $config box config (title, object_types, option_key, capability, ...)
     * @param array<int, array<string, mixed>> $fields field configs, keyed by id on the way in as add_field() keys them
     */
    public function __construct(string $id, array $config, array $fields)
    {
        $this->cmb_id = $id;
        $keyed        = [];
        foreach ($fields as $field) {
            $keyed[ (string) $field['id'] ] = $field + [ 'save_field' => true ];
        }
        $this->meta_box = [ 'id' => $id, 'fields' => $keyed ] + $config + [ 'title' => '', 'object_types' => [], 'option_key' => '', 'capability' => 'manage_options', 'show_on' => [] ];
    }

    /** @return mixed */
    public function prop(string $property, $fallback = null)
    {
        return array_key_exists($property, $this->meta_box) ? $this->meta_box[ $property ] : $fallback;
    }

    /** @return string[] */
    public function box_types(): array
    {
        return array_values((array) $this->meta_box['object_types']);
    }

    public function is_options_page_mb(): bool
    {
        return in_array('options-page', $this->box_types(), true);
    }

    /** @return string[]|string */
    public function options_page_keys()
    {
        if (! $this->is_options_page_mb()) {
            return '';
        }
        return (array) $this->meta_box['option_key'];
    }

    /** @param int|string $object_id */
    public function object_id($object_id = 0)
    {
        if ($object_id) {
            $this->object_id = $object_id;
        }
        return $this->object_id;
    }

    public function object_type(string $object_type = ''): string
    {
        if ('' !== $object_type) {
            $this->object_type = $object_type;
        }
        return $this->object_type;
    }

    /** @return Cmb2_Field_Double|false */
    public function get_field($field, $field_group = null, bool $reset_cached = false)
    {
        $id = is_array($field) ? (string) ($field['id'] ?? '') : (string) $field;
        if (! isset($this->meta_box['fields'][ $id ])) {
            return false;
        }
        return new Cmb2_Field_Double($this, $this->meta_box['fields'][ $id ], $this->object_type, $this->object_id);
    }
}

final class Cmb2_Field_Double
{
    /** @var Cmb2_Box_Double */
    private $box;

    /** @var array<string, mixed> */
    private $args;

    /** @var string */
    public $object_type;

    /** @var int|string */
    public $object_id;

    /**
     * @param array<string, mixed> $args
     * @param int|string           $object_id
     */
    public function __construct(Cmb2_Box_Double $box, array $args, string $object_type, $object_id)
    {
        $this->box         = $box;
        $this->args        = $args;
        $this->object_type = $object_type;
        $this->object_id   = $object_id;
    }

    /** @return mixed */
    public function args(string $key = '')
    {
        return '' === $key ? $this->args : ($this->args[ $key ] ?? null);
    }

    /**
     * CMB2_Field::save_field(): sanitize (a sanitization_cb first, then the
     * type's CMB2_Sanitize method), then update the stored value, or remove
     * it when the sanitized value is empty.
     *
     * @param mixed $meta_value
     * @return bool|int
     */
    public function save_field($meta_value)
    {
        $this->box->saves++;
        $value = $this->sanitize($meta_value);
        $id    = (string) $this->args['id'];
        if ('options-page' === $this->object_type) {
            $key  = (string) $this->object_id;
            $opts = get_option($key, []);
            $opts = is_array($opts) ? $opts : [];
            if ('' === $value || false === $value || null === $value || [] === $value) {
                unset($opts[ $id ]);
            } else {
                $opts[ $id ] = $value;
            }
            return update_option($key, $opts);
        }
        if ('' === $value || false === $value || null === $value || [] === $value) {
            return delete_post_meta((int) $this->object_id, $id);
        }
        return update_post_meta((int) $this->object_id, $id, $value);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitize($value)
    {
        if (isset($this->args['sanitization_cb']) && is_callable($this->args['sanitization_cb'])) {
            return call_user_func($this->args['sanitization_cb'], $value, $this->args, $this);
        }
        switch ((string) $this->args['type']) {
            case 'checkbox':
                return 'on' === $value ? 'on' : false;
            case 'text_email':
                $value = trim((string) $value);
                return is_email($value) ? $value : '';
            case 'text_url':
                return esc_url_raw((string) $value);
            case 'colorpicker':
                return (! $value || '#' === $value) ? '' : esc_attr((string) $value);
            case 'textarea':
            case 'textarea_small':
            case 'wysiwyg':
                return wp_kses_post((string) $value);
            case 'multicheck':
            case 'multicheck_inline':
                return $value;
            default:
                return is_array($value) ? array_map('sanitize_text_field', $value) : sanitize_text_field((string) $value);
        }
    }
}
