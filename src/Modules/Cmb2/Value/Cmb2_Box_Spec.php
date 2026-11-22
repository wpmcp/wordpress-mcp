<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One CMB2 box (issue #1089): its id, title, the kind of object it edits
 * (post, options-page, term, user or comment), the post types or option
 * keys it applies to, the capability an options page box asks for, its
 * fields, the hook it was registered on when known, and the CMB2 object
 * itself, which a write saves through.
 */
final class Cmb2_Box_Spec
{
    public const POST         = 'post';
    public const OPTIONS_PAGE = 'options-page';

    /**
     * @param string[]                       $post_types
     * @param string[]                       $option_keys
     * @param array<string, Cmb2_Field_Spec> $fields
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $object_type,
        public readonly array $post_types,
        public readonly array $option_keys,
        public readonly string $capability,
        public readonly bool $network,
        public readonly array $fields,
        public readonly ?string $registered_on,
        public readonly object $native
    ) {
    }

    /** Whether this pack reads values of this box: post and options page boxes. */
    public function values_readable(): bool
    {
        return self::POST === $this->object_type || self::OPTIONS_PAGE === $this->object_type;
    }
}
