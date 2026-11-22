<?php

declare(strict_types=1);

namespace WPMCP\Bench;

use WPMCP\Bench\Scenarios\Draft_Posts;
use WPMCP\Bench\Scenarios\Elementor_Phone;
use WPMCP\Bench\Scenarios\Plugin_Updates;
use WPMCP\Bench\Scenarios\Seo_Meta;
use WPMCP\Bench\Scenarios\Woo_Refund;

require_once __DIR__ . '/Data.php';
require_once __DIR__ . '/Scenario.php';
require_once __DIR__ . '/Surface.php';
require_once __DIR__ . '/scenarios/Page.php';
require_once __DIR__ . '/scenarios/1-woo-refund.php';
require_once __DIR__ . '/scenarios/2-seo-meta.php';
require_once __DIR__ . '/scenarios/3-elementor-phone.php';
require_once __DIR__ . '/scenarios/4-plugin-updates.php';
require_once __DIR__ . '/scenarios/5-draft-posts.php';

/**
 * The five benchmark scenarios, in order, and the sequence of each on a
 * surface (issue #1130). REPO-ONLY, like everything under tools/.
 */
final class Scenarios
{
    /** @return list<Scenario> */
    public static function all(): array
    {
        return [new Woo_Refund(), new Seo_Meta(), new Elementor_Phone(), new Plugin_Updates(), new Draft_Posts()];
    }

    public static function find(int $id): ?Scenario
    {
        foreach (self::all() as $scenario) {
            if ($scenario->id() === $id) {
                return $scenario;
            }
        }
        return null;
    }

    /**
     * The steps of $scenario on $surface: its own on full, derived by
     * Surface::compact() on compact.
     *
     * @param array<string, mixed> $fixtures
     * @return array<int, array<string, mixed>>
     */
    public static function sequence(Scenario $scenario, array $fixtures, string $surface): array
    {
        $full = $scenario->steps($fixtures);
        return Surface::COMPACT === $surface ? Surface::compact($scenario->searches(), $full) : $full;
    }
}
