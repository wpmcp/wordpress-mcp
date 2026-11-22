<?php

/**
 * Pro entry point, required by the public loader (src/Edition/Pro_Loader.php)
 * after the autoloader is up and before Plugin::boot(). After the split it
 * lives at pro/src/Pro/bootstrap.php and the loader has added pro/src to the
 * autoload path; before the split it runs from src/Pro/.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- an entry point: it only registers hooks.

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('WPMCP_PRO_BUILD')) {
    define('WPMCP_PRO_BUILD', true);
}

// The Pro part of Plugin.php, cut out by bin/pro-split.php. Before the split
// Plugin.php still runs that code itself and the class does not exist yet.
if (class_exists('\\WPMCP\\Pro\\Plugin_Slots')) {
    add_filter(
        \WPMCP\Edition\Edition::SLOTS_FILTER,
        static function (array $slots): array {
            $slots[] = new \WPMCP\Pro\Plugin_Slots();
            return $slots;
        }
    );
}

add_filter(
    \WPMCP\Edition\Edition::REGISTRY_ROOTS_FILTER,
    static function (array $roots): array {
        // pro/build/registry; before the split this is the free root itself.
        $dir = dirname(__DIR__, 2) . '/build/registry';
        $free = defined('WPMCP_DIR') && is_string(WPMCP_DIR) ? WPMCP_DIR . 'build/registry' : '';
        if (realpath($dir) !== realpath($free)) {
            $roots[] = $dir;
        }
        return $roots;
    }
);

add_filter(
    \WPMCP\Tools\Compose\Page_Composers::FILTER,
    static function (array $composers): array {
        $composers['elementor'] = new \WPMCP\Tools\Compose\Elementor_Page_Composer();
        return $composers;
    }
);

// The Pro skills: their own source, beside the bundled one.
add_filter(
    'wpmcp_skill_sources',
    static function (array $sources): array {
        $dir = dirname(__DIR__) . '/Skills/library';
        // Before the split this is the bundled library, already a source.
        if (is_dir($dir . '/wpmcp-elementor-editing') && realpath($dir) !== realpath(\WPMCP\Skills\Skill_Library::bundled_dir())) {
            $sources[] = ['id' => 'pro', 'label' => 'Bundled with WP MCP Pro', 'path' => $dir];
        }
        return $sources;
    }
);
