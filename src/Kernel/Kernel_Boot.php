<?php

declare(strict_types=1);

namespace WPMCP\Kernel;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The kernel boot (D11), held by the legacy Plugin (Plugin::kernel()): it
 * loads the compiled registry (build/registry, written by
 * bin/build-registry.php and shipped by every release build) once per
 * request and exposes it, so the legacy central lists can take in what
 * modules declare. Nothing else is wired yet: hooks and abilities still
 * register the legacy way.
 */
final class Kernel_Boot
{
    private ?Registry $kernel_registry = null;

    private ?Operation_Registry $kernel_operations = null;

    /** @var array<string, ?string> dispatcher => active variant, for this request */
    private array $kernel_variants = [];

    /** The compiled registry; empty (no modules) when build/registry is absent. */
    public function registry(): Registry
    {
        return $this->kernel_registry ??= Registry::from_dirs([self::registry_dir()]);
    }

    /**
     * The operations modules contribute to a legacy dispatcher pair
     * ('theme', 'plugin-data', ...), already permitted and available.
     *
     * @param ?string $variant the pair's active variant (the detected theme framework)
     * @return array<string, array<string, mixed>>
     */
    public function module_operations(string $dispatcher, ?string $variant = null): array
    {
        $this->kernel_variants[ $dispatcher ] = $variant;
        /**
         * The generator writes only class-strings it reflected.
         *
         * @var array<string, list<class-string>> $factories
         * @var array<string, class-string> $bindings
         */
        [$factories, $bindings] = [$this->registry()->factories(), $this->registry()->bindings()];
        $variants = &$this->kernel_variants;
        $this->kernel_operations ??= new Operation_Registry(
            $this->registry(),
            new Presence(),
            new Container($factories, $bindings),
            static fn (string $tier): bool => \WPMCP\MCP\Registrar::tier_permitted($tier),
            static function (string $pair) use (&$variants): ?string {
                return $variants[ $pair ] ?? null;
            },
        );
        return $this->kernel_operations->operations($dispatcher);
    }

    /** Test seam: a registry built from arrays, or null to load build/registry again. Guarded by WPMCP_TESTING. */
    public function use_registry_for_tests(?Registry $registry): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        $this->kernel_registry = $registry;
        $this->kernel_operations = null;
        $this->kernel_variants = [];
    }

    private static function registry_dir(): string
    {
        $root = defined('WPMCP_DIR') && is_string(WPMCP_DIR) ? WPMCP_DIR : dirname(__DIR__, 2) . '/';
        return rtrim($root, '/') . '/build/registry';
    }
}
