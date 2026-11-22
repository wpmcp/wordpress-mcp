<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Who walks past the gate, as a set-site-gate-bypass call asks for it (issue
 * #1099). A field left out is null and stays as stored. bypass_link is the
 * new secret ('' turns the link off); it is accepted, stored and never
 * returned.
 */
final class Bypass_Change
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
        public readonly ?string $bypass_link = null,
        public readonly ?bool $logged_in = null,
    ) {
    }

    /** @param array<mixed> $args already checked against the op's schema. */
    public static function from_args(array $args): self|Refusal
    {
        $ips = null;
        if (isset($args['ip_allowlist'])) {
            $ips = array_values(array_unique(array_map('trim', Stored::strings($args['ip_allowlist']))));
            $bad = array_filter($ips, static fn (string $ip): bool => false === filter_var($ip, FILTER_VALIDATE_IP));
            if ([] !== $bad) {
                // The count only: an address is never echoed back.
                return new Refusal('invalid_ip', sprintf('%d of the ip_allowlist entries are not IPv4 or IPv6 addresses.', count($bad)), ['invalid_count' => count($bad)]);
            }
        }
        $list = static fn (string $key): ?array => isset($args[ $key ]) ? array_values(array_unique(Stored::strings($args[ $key ]))) : null;

        return new self(
            $list('roles'),
            $list('backend_roles'),
            isset($args['users']) ? array_values(array_unique(Stored::ints($args['users']))) : null,
            $ips,
            isset($args['bypass_link']) ? Stored::text($args['bypass_link']) : null,
            isset($args['logged_in_bypass']) ? (bool) $args['logged_in_bypass'] : null,
        );
    }

    /** @return list<string> the fields the call names, as the op's argument names. */
    public function fields(): array
    {
        $named = [
            'roles' => $this->roles,
            'backend_roles' => $this->backend_roles,
            'users' => $this->users,
            'ip_allowlist' => $this->ips,
            'bypass_link' => $this->bypass_link,
            'logged_in_bypass' => $this->logged_in,
        ];
        return array_keys(array_filter($named, static fn (array|string|bool|null $value): bool => null !== $value));
    }
}
