<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where a CMB2 box's values live for one call (issue #1089): one post's
 * meta, or the option array of an options page.
 */
final class Cmb2_Target
{
    private function __construct(
        public readonly string $object_type,
        public readonly int $post_id,
        public readonly string $option_key
    ) {
    }

    public static function post(int $post_id): self
    {
        return new self(Cmb2_Box_Spec::POST, $post_id, '');
    }

    public static function option(string $option_key): self
    {
        return new self(Cmb2_Box_Spec::OPTIONS_PAGE, 0, $option_key);
    }

    public function is_post(): bool
    {
        return Cmb2_Box_Spec::POST === $this->object_type;
    }
}
