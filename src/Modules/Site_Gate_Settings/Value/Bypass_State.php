<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Who a gate plugin lets through, read from its own settings (issue #1099).
 * A field a plugin does not have is null. The IP addresses and the bypass
 * link stay inside this object: masked() is the only view that leaves it,
 * and it carries counts and booleans only.
 */
final class Bypass_State
{
    /**
     * @param list<string>|null $roles
     * @param list<string>|null $backend_roles
     * @param list<int>|null    $users
     * @param list<string>|null $ips
     */
    public function __construct(
        public readonly ?array $roles = null,
        public readonly ?array $backend_roles = null,
        public readonly ?array $users = null,
        public readonly ?array $ips = null,
        public readonly ?bool $link_set = null,
        public readonly ?bool $logged_in = null,
        public readonly ?int $excluded_paths = null,
    ) {
    }

    /** @return array<string, list<string>|int|bool> roles, counts and booleans; never an address or a link. */
    public function masked(): array
    {
        $out = [
            'roles' => $this->roles,
            'backend_roles' => $this->backend_roles,
            'user_count' => null === $this->users ? null : count($this->users),
            'ip_count' => null === $this->ips ? null : count($this->ips),
            'excluded_path_count' => $this->excluded_paths,
            'bypass_link_set' => $this->link_set,
            'logged_in_users_pass' => $this->logged_in,
        ];
        return array_filter($out, static fn (array|int|bool|null $value): bool => null !== $value);
    }
}
