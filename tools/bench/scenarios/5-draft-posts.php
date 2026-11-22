<?php

declare(strict_types=1);

namespace WPMCP\Bench\Scenarios;

use WPMCP\Bench\Data;
use WPMCP\Bench\Scenario;

/**
 * Scenario 5: "Create a draft post per row of a 10-row list and tag them".
 *
 * Fixtures: the 10 rows are in the prompt, not on the site; the seed only
 * returns them. create-post takes terms by name, so the tag needs no call
 * of its own.
 *
 * Ideal sequence on full: one create-post per row, all in one turn.
 */
final class Draft_Posts implements Scenario
{
    public function id(): int
    {
        return 5;
    }

    public function slug(): string
    {
        return 'draft-posts';
    }

    public function title(): string
    {
        return 'Create a draft post per row of a 10-row list and tag them';
    }

    /** @return list<string> */
    public function missing(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function seed(): array
    {
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = [
                'title' => sprintf('Bench Draft Idea %02d', $i),
                'body'  => sprintf('<!-- wp:paragraph --><p>Outline for idea %d: a made-up topic.</p><!-- /wp:paragraph -->', $i),
            ];
        }
        return ['rows' => $rows, 'tag' => 'bench-batch'];
    }

    /**
     * @param array<string, mixed> $fixtures
     * @return array<int, array<string, mixed>>
     */
    public function steps(array $fixtures): array
    {
        $tag  = Data::str($fixtures, 'tag');
        $rows = Data::rows($fixtures, 'rows');

        return [
            [
                'tool' => 'wpmcp/create-post',
                'each' => static fn(): array => array_map(static fn(array $row): array => [
                    'post_type' => 'post',
                    'status'    => 'draft',
                    'title'     => Data::str($row, 'title'),
                    'content'   => Data::str($row, 'body'),
                    'terms'     => ['post_tag' => [$tag]],
                ], $rows),
                'as'   => 'created',
            ],
        ];
    }

    /** @return list<string> */
    public function searches(): array
    {
        return ['create-post'];
    }

    /**
     * @param array<string, mixed> $fixtures
     * @param array<string, mixed> $state
     */
    public function verify(array $fixtures, array $state): ?string
    {
        $posts = get_posts(['post_type' => 'post', 'post_status' => 'draft', 'tag' => Data::str($fixtures, 'tag'), 'numberposts' => 50]);
        return 10 === count($posts) ? null : sprintf('expected 10 tagged drafts, found %d', count($posts));
    }
}
