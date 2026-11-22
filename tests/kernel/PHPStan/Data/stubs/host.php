<?php

// A host plugin: neither PHP, WordPress nor WPMCP.

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration -- global stand-ins, like the stubs they imitate.

if (! class_exists('Acme_Gate_Order', false)) {
    final class Acme_Gate_Order
    {
        public static function find(int $id): ?self
        {
            return null;
        }
    }
}

if (! function_exists('acme_gate_orders')) {
    /** @return list<mixed> */
    function acme_gate_orders(): array
    {
        return [];
    }
}
