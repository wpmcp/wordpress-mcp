<?php

/**
 * The site-side half of the benchmark's HTTP mode (issue #1130), run with
 * WP-CLI on the site under test:
 *
 *   wp --path=SITE eval-file tools/bench/remote.php seed <scenario>
 *   wp --path=SITE eval-file tools/bench/remote.php verify <scenario> <run.json>
 *   wp --path=SITE eval-file tools/bench/remote.php prune
 *
 * seed runs the scenario's seed() inside that WordPress and prints
 * {"fixtures": ...}, or {"skipped": "needs ..."} when a plugin it needs is
 * not active. Everything seed() creates is tagged (_wpmcp_bench post and
 * user meta), so prune can remove it: tagged posts of every type and status,
 * the orders of tagged customers, posts the run created under the bench tag
 * (scenario 5), the bench tag itself, and the fake update offer of scenario
 * 4. verify reads {"fixtures", "state"} from the file and prints
 * {"problem": null|string}.
 *
 * Meant for a disposable local site: it writes fixtures, and the scenarios
 * write to them through the tools (a refund, SEO meta, Elementor data).
 *
 * REPO-ONLY, like the rest of tools/.
 */

declare(strict_types=1);

use WPMCP\Bench\Data;
use WPMCP\Bench\Scenario;
use WPMCP\Bench\Scenarios;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    exit(1);
}

require_once __DIR__ . '/Scenarios.php';

const WPMCP_BENCH_TAG_META = '_wpmcp_bench';

$wpmcp_bench_args   = Data::strs(get_defined_vars(), 'args');
$wpmcp_bench_action = $wpmcp_bench_args[0] ?? '';

/** Prints $message on stderr and ends the WP-CLI command with exit 1. */
function wpmcp_bench_remote_fail(string $message): never
{
    fwrite(STDERR, 'Error: ' . $message . "\n");
    exit(1);
}

function wpmcp_bench_remote_scenario(string $id): Scenario
{
    $scenario = Scenarios::find((int) $id);
    return null === $scenario ? wpmcp_bench_remote_fail('no scenario ' . $id) : $scenario;
}

/** @param array<string, mixed> $data */
function wpmcp_bench_remote_print(array $data): void
{
    echo Data::json($data, JSON_UNESCAPED_SLASHES) . "\n";
}

/** @return array{posts: int, users: int, orders: int, terms: int} */
function wpmcp_bench_remote_prune(): array
{
    $removed = ['posts' => 0, 'users' => 0, 'orders' => 0, 'terms' => 0];

    $users = Data::ints([get_users(['meta_key' => WPMCP_BENCH_TAG_META, 'fields' => 'ID', 'number' => -1])], 0);
    if (function_exists('wc_get_orders') && [] !== $users) {
        $orders = wc_get_orders(['customer_id' => $users, 'limit' => -1, 'status' => 'any']);
        foreach (is_array($orders) ? $orders : [] as $order) {
            if ($order instanceof \WC_Order) {
                $order->delete(true);
                $removed['orders']++;
            }
        }
    }

    $posts = Data::ints([get_posts([
        'post_type'   => 'any',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_key'    => WPMCP_BENCH_TAG_META,
    ])], 0);
    $tag   = get_term_by('slug', 'bench-batch', 'post_tag');
    if ($tag instanceof WP_Term) {
        $posts = array_merge($posts, Data::ints([get_posts(['post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'tag_id' => $tag->term_id])], 0));
    }
    foreach (array_unique($posts) as $id) {
        if (wp_delete_post($id, true) instanceof WP_Post) {
            $removed['posts']++;
        }
    }
    if ($tag instanceof WP_Term && true === wp_delete_term($tag->term_id, 'post_tag')) {
        $removed['terms']++;
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $id) {
        if (wp_delete_user($id)) {
            $removed['users']++;
        }
    }

    $offer = get_site_transient('update_plugins');
    if (is_object($offer) && property_exists($offer, 'response') && is_array($offer->response)) {
        foreach ($offer->response as $update) {
            if (is_object($update) && property_exists($update, 'new_version') && '99.0.0' === $update->new_version) {
                delete_site_transient('update_plugins');
                break;
            }
        }
    }

    return $removed;
}

switch ($wpmcp_bench_action) {
    case 'seed':
        $scenario = wpmcp_bench_remote_scenario($wpmcp_bench_args[1] ?? '');
        $missing  = $scenario->missing();
        if ([] !== $missing) {
            wpmcp_bench_remote_print(['skipped' => 'needs ' . implode(', ', $missing)]);
            break;
        }
        add_action('wp_insert_post', static function (int $id): void {
            update_post_meta($id, WPMCP_BENCH_TAG_META, 1);
        });
        add_action('user_register', static function (int $id): void {
            update_user_meta($id, WPMCP_BENCH_TAG_META, 1);
        });
        wpmcp_bench_remote_print(['fixtures' => $scenario->seed()]);
        break;

    case 'verify':
        $scenario = wpmcp_bench_remote_scenario($wpmcp_bench_args[1] ?? '');
        $file     = $wpmcp_bench_args[2] ?? '';
        $run      = '' !== $file && is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        $problem  = is_array($run)
            ? $scenario->verify(Data::map($run, 'fixtures'), Data::map($run, 'state'))
            : 'no run to verify';
        wpmcp_bench_remote_print(['problem' => $problem]);
        break;

    case 'prune':
        wpmcp_bench_remote_print(['removed' => wpmcp_bench_remote_prune()]);
        break;

    default:
        wpmcp_bench_remote_fail('usage: wp eval-file tools/bench/remote.php seed <scenario> | verify <scenario> <run.json> | prune');
}
