<?php

declare(strict_types=1);

namespace WPMCP\Bench\Scenarios;

use WPMCP\Bench\Data;
use WPMCP\Bench\Scenario;
use WPMCP\Tools\SEO\SEO_Adapter;

/**
 * Scenario 2: "Set the meta description on every published page that has
 * none" (SEO, 40 pages).
 *
 * Fixtures: 40 published pages, 15 of them (every other one up to page 30) with a description already, and
 * 5 drafts without one (which must be left alone). Needs an SEO plugin the
 * SEO tools write through (the test install has Yoast).
 *
 * Ideal sequence on full: list the published pages (one call, per_page
 * 100), read each page's SEO meta (no listing carries it, so one call per
 * page, all in one turn), then set the description on each page that has
 * none (one turn).
 */
final class Seo_Meta implements Scenario
{
    public function id(): int
    {
        return 2;
    }

    public function slug(): string
    {
        return 'seo-meta';
    }

    public function title(): string
    {
        return 'Set the meta description on every published page that has none (40 pages)';
    }

    public function missing(): array
    {
        return function_exists('wp_has_ability') && wp_has_ability('wpmcp/update-seo-meta') ? [] : ['an SEO plugin (Yoast SEO, Rank Math or similar)'];
    }

    public function seed(): array
    {
        $with    = [];
        $without = [];
        for ($i = 1; $i <= 40; $i++) {
            $id = Page::create([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => sprintf('Bench Service Page %02d', $i),
                'post_content' => sprintf('<!-- wp:paragraph --><p>Bench page %d about a made-up service.</p><!-- /wp:paragraph -->', $i),
            ]);
            if (0 === $i % 2 && count($with) < 15) {
                SEO_Adapter::update_meta($id, ['description' => sprintf('Existing description %d.', $i)]);
                $with[] = $id;
            } else {
                $without[] = $id;
            }
        }
        $drafts = [];
        for ($i = 1; $i <= 5; $i++) {
            $drafts[] = Page::create([
                'post_type'   => 'page',
                'post_status' => 'draft',
                'post_title'  => sprintf('Bench Draft %d', $i),
            ]);
        }

        return ['with' => $with, 'without' => $without, 'drafts' => $drafts];
    }

    public function steps(array $fixtures): array
    {
        return [
            ['tool' => 'wpmcp/list-posts', 'args' => ['post_type' => 'page', 'status' => 'publish', 'per_page' => 100], 'as' => 'pages'],
            [
                'tool' => 'wpmcp/get-seo-meta',
                'each' => static fn(array $r): array => array_map(
                    static fn(array $p): array => ['post_id' => Data::int($p, 'post_id')],
                    Data::rows($r, 'pages', 'posts')
                ),
                'as'   => 'meta',
            ],
            [
                'tool' => 'wpmcp/update-seo-meta',
                'each' => static function (array $r): array {
                    $calls = [];
                    foreach (Data::rows($r, 'meta') as $meta) {
                        if ('' === trim(Data::str($meta, 'description'))) {
                            $id      = Data::int($meta, 'post_id');
                            $calls[] = ['post_id' => $id, 'description' => sprintf('Bench description for page %d.', $id)];
                        }
                    }
                    return $calls;
                },
                'as'   => 'updated',
            ],
        ];
    }

    public function searches(): array
    {
        return ['seo', 'list-posts'];
    }

    public function verify(array $fixtures, array $state): ?string
    {
        if (40 !== count(Data::arr($state, 'pages', 'posts'))) {
            return 'list-posts did not return the 40 published pages';
        }
        foreach (Data::ints($fixtures, 'without') as $id) {
            if (! str_starts_with(self::description($id), 'Bench description')) {
                return sprintf('page %d still has no description', $id);
            }
        }
        foreach (Data::ints($fixtures, 'with') as $id) {
            if (! str_starts_with(self::description($id), 'Existing description')) {
                return sprintf('page %d lost its existing description', $id);
            }
        }
        foreach (Data::ints($fixtures, 'drafts') as $id) {
            if ('' !== self::description($id)) {
                return sprintf('draft %d was changed', $id);
            }
        }
        $updated = count(Data::arr($state, 'updated'));
        return 25 === $updated ? null : sprintf('expected 25 updates, made %d', $updated);
    }

    private static function description(int $id): string
    {
        return Data::str(SEO_Adapter::get_meta($id), 'description');
    }
}
