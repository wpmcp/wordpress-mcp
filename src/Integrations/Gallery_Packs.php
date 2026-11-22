<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The gallery and slider packs of issue #705 as one entry for the plugin-data
 * pair: MetaSlider, Modula, Envira Gallery, Smart Slider 3 and NextGEN
 * Gallery. It only merges their packs, so the pair (which does not read the
 * module registry yet) takes one registration line for the five, and wires
 * their rollback refreshers from one Plugin::ROLLBACK_REFRESHERS row.
 */
final class Gallery_Packs
{
    public static function active(): bool
    {
        return MetaSlider_Pack::active() || Modula_Pack::active() || Envira_Pack::active() || SmartSlider_Pack::active() || NextGen_Pack::active();
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return array_merge(
            MetaSlider_Pack::operations(),
            Modula_Pack::operations(),
            Envira_Pack::operations(),
            SmartSlider_Pack::operations(),
            NextGen_Pack::operations(),
        );
    }

    /**
     * operation_hosts() groups: plugin name, served by several plugins, ops.
     *
     * @return list<array{0: string, 1: bool, 2: array<string, array<string, mixed>>}>
     */
    public static function host_groups(): array
    {
        return [
            [ 'MetaSlider', false, MetaSlider_Pack::operations() ],
            [ 'Modula', false, Modula_Pack::operations() ],
            [ 'Envira Gallery', false, Envira_Pack::operations() ],
            [ 'Smart Slider 3', false, SmartSlider_Pack::operations() ],
            [ 'NextGEN Gallery', false, NextGen_Pack::operations() ],
        ];
    }

    /**
     * Hooks the packs' refreshers, on init from one ROLLBACK_REFRESHERS row.
     * Envira serves galleries from its own transients and hides a trashed one
     * by a flag its admin-only hooks set, so an undo flushes the one and a
     * trash or untrash keeps the other in step. Smart Slider 3 and NextGEN
     * Gallery render from caches of their own, so an undo of one of their
     * rows clears them as their own saves do.
     */
    public static function attach_refreshers(): void
    {
        add_action('wpmcp_rollback_post_restored', [ Envira_Pack::class, 'after_post_restored' ], 10, 1);
        add_action('trashed_post', [ Envira_Pack::class, 'after_trash' ], 10, 1);
        add_action('untrashed_post', [ Envira_Pack::class, 'after_untrash' ], 10, 1);
        add_action('wpmcp_rollback_db_rows_restored', [ SmartSlider_Pack::class, 'after_rows_restored' ], 10, 1);
        add_action('wpmcp_rollback_db_rows_restored', [ NextGen_Pack::class, 'after_rows_restored' ], 10, 1);
    }
}
