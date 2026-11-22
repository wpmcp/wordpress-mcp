<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Adapter;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The admin-like context CMB2 boxes are registered in (issue #1089).
 *
 * CMB2's bootstrap fires cmb2_admin_init only when is_admin(), and most
 * sites register their boxes there, so a REST or MCP request never sees
 * them in CMB2_Boxes. Before reading the boxes this fires cmb2_admin_init
 * once in the request, when CMB2 itself has not, so the callbacks register
 * their boxes as they do on an admin screen. A callback that fails outside
 * wp-admin (calling an admin-only function, say) is contained, and the
 * boxes it registered before failing still count. The ids seen before and
 * after are kept on this object, so a box can be reported as registered
 * on cmb2_init or on cmb2_admin_init by the call that fired the hook.
 *
 * Filter wpmcp_cmb2_emulate_admin_init (true) turns the emulation off.
 */
final class Cmb2_Admin_Context
{
    public const HOOK = 'cmb2_admin_init';

    /** @var array<string, string> box id => the hook it was registered on, as seen by ensure(). */
    private array $origins = [];

    /**
     * Fire cmb2_admin_init when it has not run in this request.
     *
     * @param callable(): string[] $box_ids the ids of the boxes CMB2 holds now.
     */
    public function ensure(callable $box_ids): void
    {
        if (did_action(self::HOOK) > 0 || is_admin()) {
            return;
        }
        if (! (bool) apply_filters('wpmcp_cmb2_emulate_admin_init', true)) {
            return;
        }
        $before = $box_ids();
        try {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- CMB2's own registration hook, fired as its bootstrap fires it on an admin screen.
            do_action('cmb2_admin_init');
        } catch (\Throwable $e) {
            // A box callback meant for wp-admin only; the boxes it already added stay.
            unset($e);
        }
        $origins = [];
        foreach ($before as $id) {
            $origins[ $id ] = 'cmb2_init';
        }
        foreach (array_diff($box_ids(), $before) as $id) {
            $origins[ $id ] = self::HOOK;
        }
        $this->origins = $origins;
    }

    /** Whether cmb2_admin_init has run in this request, by CMB2 or by ensure(). */
    public function loaded(): bool
    {
        return did_action(self::HOOK) > 0 || is_admin();
    }

    /** The hook a box was registered on, when this request saw it happen. */
    public function origin(string $box_id): ?string
    {
        return $this->origins[ $box_id ] ?? null;
    }
}
