<?php

declare(strict_types=1);

namespace WPMCP\Bench\Scenarios;

use WPMCP\Bench\Data;
use WPMCP\Bench\Scenario;

/**
 * Scenario 4: "List plugins with updates available and the pages that use
 * each one's blocks or widgets".
 *
 * Fixtures: an update offer (the update_plugins transient, no network) for
 * WooCommerce and Elementor; 12 pages: 3 with WooCommerce blocks, 4 built
 * with Elementor, 5 plain.
 *
 * Ideal sequence on full: list-plugins (it carries update_available), then
 * in one turn a list-posts search for each block namespace of an updated
 * plugin (wp:woocommerce/) and one page listing for the widget plugin
 * (list-posts reports is_elementor per post). Read-only.
 */
final class Plugin_Updates implements Scenario
{
    /** What the agent knows about each plugin: a block namespace it searches post content for. */
    private const BLOCKS = ['woocommerce/woocommerce.php' => 'wp:woocommerce/'];

    /** Page builders: list-posts reports is_elementor per post. */
    private const BUILDERS = ['elementor/elementor.php' => 'is_elementor'];

    public function id(): int
    {
        return 4;
    }

    public function slug(): string
    {
        return 'plugin-updates';
    }

    public function title(): string
    {
        return 'List plugins with updates available and the pages that use their blocks or widgets';
    }

    public function missing(): array
    {
        $missing = [];
        if (! class_exists('WooCommerce')) {
            $missing[] = 'WooCommerce';
        }
        if (! defined('ELEMENTOR_VERSION')) {
            $missing[] = 'Elementor';
        }
        return $missing;
    }

    public function seed(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $offer               = new \stdClass();
        $offer->last_checked = time();
        $offer->checked      = [];
        $response            = [];
        foreach (['woocommerce/woocommerce.php' => 'woocommerce', 'elementor/elementor.php' => 'elementor'] as $file => $slug) {
            $response[ $file ] = (object) [
                'slug'        => $slug,
                'plugin'      => $file,
                'new_version' => '99.0.0',
                'package'     => '',
                'url'         => 'https://example.test/' . $slug,
            ];
        }
        $offer->response = $response;
        set_site_transient('update_plugins', $offer);

        $woo = [];
        foreach (['Shop', 'Featured', 'Cart Help'] as $i => $name) {
            $woo[] = self::page("Bench {$name}", '<!-- wp:woocommerce/product-collection {"queryId":' . ($i + 1) . '} /--><!-- wp:paragraph --><p>Bench.</p><!-- /wp:paragraph -->');
        }
        $elementor = [];
        for ($i = 1; $i <= 4; $i++) {
            $id = self::page("Bench Landing {$i}", '');
            update_post_meta($id, '_elementor_edit_mode', 'builder');
            update_post_meta($id, '_elementor_data', wp_slash(Data::json([[
                'id' => "w{$i}", 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => "Landing {$i}"], 'elements' => [],
            ]])));
            $elementor[] = $id;
        }
        for ($i = 1; $i <= 5; $i++) {
            self::page("Bench Plain {$i}", '<!-- wp:paragraph --><p>Plain page.</p><!-- /wp:paragraph -->');
        }

        return ['woo' => $woo, 'elementor' => $elementor];
    }

    public function steps(array $fixtures): array
    {
        return [
            ['tool' => 'wpmcp/list-plugins', 'args' => [], 'as' => 'plugins'],
            [
                'tool' => 'wpmcp/list-posts',
                'each' => static function (array $r): array {
                    // Block searches first, then one page listing for the
                    // builders: verify reads the results in that order.
                    $calls   = [];
                    $listing = false;
                    $plugins = null !== Data::at($r, 'plugins', 'plugins') ? Data::rows($r, 'plugins', 'plugins') : Data::rows($r, 'plugins');
                    foreach ($plugins as $plugin) {
                        if (! Data::truthy($plugin, 'update_available')) {
                            continue;
                        }
                        $file = null !== Data::at($plugin, 'file') ? Data::str($plugin, 'file') : Data::str($plugin, 'plugin');
                        if (isset(self::BLOCKS[ $file ])) {
                            $calls[] = ['post_type' => 'page', 'search' => self::BLOCKS[ $file ], 'per_page' => 100];
                        }
                        $listing = $listing || isset(self::BUILDERS[ $file ]);
                    }
                    if ($listing) {
                        $calls[] = ['post_type' => 'page', 'per_page' => 100];
                    }
                    if ([] === $calls) {
                        throw new \RuntimeException('list-plugins showed no plugin with an update');
                    }
                    return $calls;
                },
                'as'   => 'pages',
            ],
        ];
    }

    public function searches(): array
    {
        return ['plugins', 'list-posts'];
    }

    public function verify(array $fixtures, array $state): ?string
    {
        $woo_ids = array_map(static fn(array $p): int => Data::int($p, 'post_id'), Data::rows($state, 'pages', 0, 'posts'));
        sort($woo_ids);
        $want = Data::ints($fixtures, 'woo');
        sort($want);
        if ($woo_ids !== $want) {
            return sprintf('the block search returned [%s], the WooCommerce pages are [%s]', implode(',', $woo_ids), implode(',', $want));
        }
        $el = [];
        foreach (Data::rows($state, 'pages', 1, 'posts') as $p) {
            if (Data::truthy($p, 'is_elementor')) {
                $el[] = Data::int($p, 'post_id');
            }
        }
        sort($el);
        $want = Data::ints($fixtures, 'elementor');
        sort($want);
        return $el === $want ? null : sprintf('the listing flagged [%s] as Elementor, the Elementor pages are [%s]', implode(',', $el), implode(',', $want));
    }

    private static function page(string $title, string $content): int
    {
        return Page::create(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content]);
    }
}
