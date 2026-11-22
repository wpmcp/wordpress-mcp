<?php

declare(strict_types=1);

namespace WPMCP\Bench\Scenarios;

use WPMCP\Bench\Data;
use WPMCP\Bench\Scenario;
use WPMCP\Tools\Search\Reindex_Search;
use WPMCP\Tools\Search\Search_Index_Store;

/**
 * Scenario 3: "Replace the phone number in every Elementor page that
 * contains the old one".
 *
 * Fixtures: 8 Elementor pages. 5 carry the old number, one of them in two
 * widgets; 3 do not. The search index is built, as it is on a site where
 * pages were saved after the plugin was active.
 *
 * find-replace-content refuses _elementor_data (protected meta), so the
 * ideal sequence on full is: search-content for the number (one call), read
 * each page that hit with get-elementor-data (one turn), then one
 * batch-update per page with every element that holds the number (one
 * turn; batch-update needs the expected_hash from the read).
 */
final class Elementor_Phone implements Scenario
{
    private const OLD_PHONE = '(555) 010-4477';
    private const NEW_PHONE = '(555) 010-9900';

    public function id(): int
    {
        return 3;
    }

    public function slug(): string
    {
        return 'elementor-phone';
    }

    public function title(): string
    {
        return 'Replace the phone number in every Elementor page that contains the old one';
    }

    public function missing(): array
    {
        return defined('ELEMENTOR_VERSION') ? [] : ['Elementor'];
    }

    public function seed(): array
    {
        $old   = self::OLD_PHONE;
        $with  = [];
        $other = [];
        for ($i = 1; $i <= 8; $i++) {
            $has      = $i <= 5;
            $children = [
                self::widget("h{$i}", 'heading', ['title' => "Bench Location {$i}"]),
                self::widget("t{$i}", 'text-editor', ['editor' => $has
                    ? "<p>Call us on {$old} from 9 to 5.</p>"
                    : '<p>Email us any time.</p>']),
            ];
            if (2 === $i) {
                $children[] = self::widget("b{$i}", 'button', ['text' => "Call {$old}", 'link' => ['url' => 'tel:5550104477']]);
            }
            $data = [[
                'id'       => "c{$i}",
                'elType'   => 'container',
                'settings' => [],
                'elements' => $children,
            ]];

            $id = Page::create([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => "Bench Elementor Location {$i}",
                'post_content' => '',
            ]);
            update_post_meta($id, '_elementor_edit_mode', 'builder');
            update_post_meta($id, '_elementor_template_type', 'wp-page');
            update_post_meta($id, '_elementor_version', defined('ELEMENTOR_VERSION') ? Data::str([constant('ELEMENTOR_VERSION')], 0) : '3.0.0');
            update_post_meta($id, '_elementor_data', wp_slash(Data::json($data)));

            if ($has) {
                $with[] = $id;
            } else {
                $other[] = $id;
            }
        }

        // The index a live site has: rebuilt here because the fixtures were
        // written straight to postmeta, which the save-time indexer skips.
        Search_Index_Store::ensure_installed();
        $reindex = new Reindex_Search();
        $offset  = 0;
        do {
            $step   = $reindex->handle(['full' => true, 'offset' => $offset]);
            $offset = Data::int($step, 'next_offset');
        } while (! Data::truthy($step, 'complete') && $offset > 0);

        return ['with' => $with, 'other' => $other];
    }

    public function steps(array $fixtures): array
    {
        return [
            ['tool' => 'wpmcp/search-content', 'args' => ['query' => self::OLD_PHONE, 'post_types' => ['page'], 'limit' => 50], 'as' => 'hits'],
            [
                'tool' => 'wpmcp/get-elementor-data',
                'each' => static function (array $r): array {
                    $ids = [];
                    foreach (Data::rows($r, 'hits', 'results') as $hit) {
                        $ids[ Data::int($hit, 'post_id') ] = true;
                    }
                    if ([] === $ids) {
                        throw new \RuntimeException('search-content found no page with the old number');
                    }
                    return array_map(static fn(int $id): array => ['post_id' => $id], array_keys($ids));
                },
                'as'   => 'pages',
            ],
            [
                'tool' => 'wpmcp/batch-update',
                'each' => static function (array $r): array {
                    $calls = [];
                    foreach (Data::rows($r, 'pages') as $page) {
                        $updates = self::phone_updates(Data::rows($page, 'elements'));
                        if ([] !== $updates) {
                            $calls[] = ['post_id' => Data::int($page, 'post_id'), 'expected_hash' => Data::str($page, 'data_hash'), 'updates' => $updates];
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
        return ['search-content', 'elementor'];
    }

    public function verify(array $fixtures, array $state): ?string
    {
        foreach (Data::ints($fixtures, 'with') as $id) {
            $data = Data::str([get_post_meta($id, '_elementor_data', true)], 0);
            if (str_contains($data, self::OLD_PHONE) || ! str_contains($data, self::NEW_PHONE)) {
                return sprintf('page %d still has the old number', $id);
            }
        }
        foreach (Data::ints($fixtures, 'other') as $id) {
            if (str_contains(Data::str([get_post_meta($id, '_elementor_data', true)], 0), self::NEW_PHONE)) {
                return sprintf('page %d was changed but never had the number', $id);
            }
        }
        $updated = count(Data::arr($state, 'updated'));
        return 5 === $updated ? null : sprintf('expected 5 page updates, made %d', $updated);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{id: string, elType: string, widgetType: string, settings: array<string, mixed>, elements: list<never>}
     */
    private static function widget(string $id, string $type, array $settings): array
    {
        return ['id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => []];
    }

    /**
     * Every element of a parsed Elementor tree whose string settings hold
     * the old number, with those settings replaced.
     *
     * @param list<array<mixed>> $elements
     * @return list<array{element_id: string, settings: array<string, string>}>
     */
    private static function phone_updates(array $elements): array
    {
        $out = [];
        foreach ($elements as $el) {
            $changed = [];
            foreach (Data::map($el, 'settings') as $key => $value) {
                if (is_string($value) && str_contains($value, self::OLD_PHONE)) {
                    $changed[ $key ] = str_replace(self::OLD_PHONE, self::NEW_PHONE, $value);
                }
            }
            if ([] !== $changed) {
                $out[] = ['element_id' => Data::str($el, 'id'), 'settings' => $changed];
            }
            $children = null !== Data::at($el, 'elements') ? Data::rows($el, 'elements') : Data::rows($el, 'children');
            $out      = array_merge($out, self::phone_updates($children));
        }
        return $out;
    }
}
