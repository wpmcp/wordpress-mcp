<?php

/**
 * Stand-in for an ACF Extended module (issue #1089), recorded from ACF
 * Extended 0.9.2.8 (includes/module.php and includes/modules/*): a module
 * has a name (options_page, form, block_type), the post type it stores its
 * items in (acfe-dop, acfe-form, acfe-dbt), is_active() following its
 * modules/* setting, and get_items() returning every item, from the
 * database and from local PHP or JSON files, as arrays with ID, name and
 * label prepended.
 *
 * Handed to the pack through the wpmcp_acfe_modules filter, so no global
 * ACF Extended function or class is defined and no other test sees it.
 */

namespace WPMCP\Tests\Support;

final class Acfe_Module_Double
{
    /** @var string */
    public $name;

    /** @var string */
    public $post_type;

    /** @var bool */
    public $active;

    /** @var array<int, array<string, mixed>> */
    public $items;

    /** @param array<int, array<string, mixed>> $items */
    public function __construct(string $name, string $post_type, array $items, bool $active = true)
    {
        $this->name      = $name;
        $this->post_type = $post_type;
        $this->items     = $items;
        $this->active    = $active;
    }

    public function is_active(): bool
    {
        return $this->active;
    }

    /** @return array<int, array<string, mixed>> */
    public function get_items($source = 'any'): array
    {
        return $this->items;
    }
}
