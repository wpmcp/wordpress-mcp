<?php

// Stand-ins for WordPress core: GateRuleTestCase tells SymbolOrigin that
// symbols declared in this file are WordPress, as the real wordpress-stubs
// package is. The names are unique so loading them next to the other fast
// tier tests (and tools/registry/wp-stubs.php) collides with nothing.

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration -- global stand-ins, like the stubs they imitate.

if (! class_exists('WP_Gate_Fixture_Error', false)) {
    final class WP_Gate_Fixture_Error
    {
        public function __construct(string $code = '', string $message = '')
        {
        }
    }
}

if (! class_exists('WP_Gate_Fixture_Registry', false)) {
    final class WP_Gate_Fixture_Registry
    {
        public static function get_instance(): self
        {
            return new self();
        }
    }
}

if (! function_exists('wp_gate_fixture_option')) {
    function wp_gate_fixture_option(string $option, mixed $default = false): mixed
    {
        return $default;
    }
}
