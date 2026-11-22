<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Adapter;

use WPMCP\Modules\Site_Gate_Settings\Value\Refusal;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Checks every gate store shares (issue #1099): which content or bypass
 * fields a plugin has, that roles and users exist, and that a logo is an
 * image in the media library.
 */
final class Field_Rules
{
    /**
     * @param list<string> $asked
     * @param list<string> $supported
     */
    public function first_unsupported(string $label, array $asked, array $supported): ?Refusal
    {
        foreach ($asked as $field) {
            if (! in_array($field, $supported, true)) {
                return Refusal::unsupported($label, $field, $supported);
            }
        }
        return null;
    }

    /**
     * @param list<string> $roles
     * @param list<string> $extra role values the plugin accepts besides real roles
     */
    public function role_refusal(array $roles, array $extra = []): ?Refusal
    {
        $known = array_merge(array_map('strval', array_keys(wp_roles()->roles)), $extra);
        $unknown = array_values(array_diff($roles, $known));
        if ([] === $unknown) {
            return null;
        }
        return new Refusal('unknown_role', 'These roles do not exist on this site: ' . implode(', ', $unknown) . '.', ['unknown' => $unknown, 'known' => $known]);
    }

    public function logo_refusal(int $logo): ?Refusal
    {
        if (0 === $logo || wp_attachment_is_image($logo)) {
            return null;
        }
        return new Refusal('invalid_logo', sprintf('logo must be the id of an image in the media library (or 0 to remove it); %d is not one.', $logo), ['logo' => $logo]);
    }

    /** @param list<int> $users */
    public function users_refusal(array $users): ?Refusal
    {
        $missing = array_values(array_filter($users, static fn (int $id): bool => false === get_userdata($id)));
        if ([] === $missing) {
            return null;
        }
        return new Refusal('unknown_user', 'These user ids do not exist: ' . implode(', ', $missing) . '.', ['unknown' => $missing]);
    }

    /**
     * @param list<string> $roles
     * @param list<string> $allowed
     */
    public function intersects(array $roles, array $allowed): bool
    {
        return [] !== array_intersect($roles, $allowed);
    }

    /** @return list<string> the acting user's roles. */
    public function current_roles(): array
    {
        return array_values(array_map('strval', wp_get_current_user()->roles));
    }
}
