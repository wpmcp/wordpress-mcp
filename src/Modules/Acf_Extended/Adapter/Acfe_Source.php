<?php

declare(strict_types=1);

namespace WPMCP\Modules\Acf_Extended\Adapter;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The module boundary to ACF Extended and ACF (issue #1089): its options
 * page, form and block type modules, ACF's UI options pages and a field
 * group with its fields.
 */
interface Acfe_Source
{
    public const KINDS = [ 'options_page', 'form', 'block_type' ];

    public const UI_OPTIONS_PAGE = 'acf-ui-options-page';

    /** Whether ACF Extended is loaded. */
    public function active(): bool;

    /** Whether the module for a kind is switched on (its modules/* setting). */
    public function module_active(string $kind): bool;

    /** @return array<int, array<string, mixed>> the module's items. */
    public function items(string $kind): array;

    /** @return array<int, array<string, mixed>> ACF's UI options pages, each with ID and name prepended. */
    public function ui_options_pages(): array;

    /** The post type the module stores a kind's items in, or the kind when it names none. */
    public function post_type(string $kind): string;

    /** @return array<string, mixed>|null the ACF field group with this key. */
    public function field_group(string $key): ?array;

    /**
     * @param array<string, mixed> $group
     * @return array<int|string, mixed> the group's fields.
     */
    public function fields(array $group): array;
}
