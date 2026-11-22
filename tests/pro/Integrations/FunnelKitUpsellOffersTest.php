<?php

declare(strict_types=1);

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\FunnelKit_Funnel_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/funnelkit-tables.php';
require_once __DIR__ . '/../../support/funnelkit-rest-doubles.php';
require_once __DIR__ . '/../../support/funnelkit-upsell-doubles.php';

/**
 * Issues #1021 and #500: FunnelKit upsell offer writes on the theme
 * dispatcher pair, through Funnel Builder Pro's own routes and its
 * WFOCU_Common class, stood in for here
 * (tests/support/funnelkit-upsell-doubles.php) at the same paths, with the
 * same parameters, permission rule and storage.
 *
 * An offer is a wfocu_offer post listed in its upsell step's _funnel_steps
 * meta, not in the funnel's steps JSON, so these also pin that the funnel
 * snapshot covers the offer posts an upsell step lists.
 */
final class FunnelKitUpsellOffersTest extends \WP_UnitTestCase
{
    private const OPS = [
        'create-funnelkit-upsell-offer',
        'update-funnelkit-upsell-offer',
        'set-funnelkit-upsell-offer-products',
        'delete-funnelkit-upsell-offer',
    ];

    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_funnelkit_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_funnelkit_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('wc_get_product')) {
            $this->markTestSkipped('Needs WooCommerce.');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        get_userdata($this->admin)->add_cap('manage_woocommerce');
        wp_set_current_user($this->admin);
        add_filter('wpmcp_funnelkit_active', '__return_true');
        add_filter('wpmcp_funnelkit_offers_active', '__return_true');
        wpmcp_test_funnelkit_upsell_doubles();
        wpmcp_test_funnelkit_rest_doubles();
    }

    protected function tearDown(): void
    {
        wpmcp_test_funnelkit_upsell_reset();
        wpmcp_test_funnelkit_rest_reset();
        remove_all_filters('wpmcp_funnelkit_active');
        remove_all_filters('wpmcp_funnelkit_offers_active');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function write(string $op, array $args, array $extra = []): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ] + $extra);
    }

    private function ok(string $op, array $args, array $extra = []): array
    {
        $out = $this->write($op, $args, $extra);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    /** @return array{0:int,1:int} funnel id and upsell step id. */
    private function upsell(): array
    {
        $funnel = (int) $this->ok('create-funnelkit-funnel', [ 'title' => 'Spring' ])['result']['funnel']['id'];
        $this->ok('add-funnelkit-step', [ 'funnel_id' => $funnel, 'type' => 'wc_checkout', 'title' => 'Checkout' ]);
        // Funnel Builder Pro adds the upsell step; the free routes stood in
        // for here cannot, so it is placed the way FunnelKit stores it.
        $step = self::factory()->post->create([ 'post_type' => 'wfocu_funnel', 'post_title' => 'Upsells', 'post_status' => 'publish' ]);
        update_post_meta($step, '_bwf_in_funnel', $funnel);
        $steps   = wpmcp_test_funnelkit_steps($funnel);
        $steps[] = [ 'type' => 'wc_upsells', 'id' => $step ];
        wpmcp_test_funnelkit_save_steps($funnel, $steps);
        return [ $funnel, $step ];
    }

    private function offer(int $upsell, string $title = 'Extra case', string $type = 'upsell'): int
    {
        return (int) $this->ok('create-funnelkit-upsell-offer', [ 'step_id' => $upsell, 'title' => $title, 'type' => $type ])['result']['offer']['offer_id'];
    }

    private function product(string $regular, string $sale = '', string $name = 'Case'): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price($regular);
        if ('' !== $sale) {
            $product->set_sale_price($sale);
        }
        $product->set_status('publish');
        return $product->save();
    }

    private function state(int $funnel_id): array
    {
        return FunnelKit_Funnel_Snapshot::capture($funnel_id)['data'];
    }

    /** @return int[] */
    private function listed(int $upsell): array
    {
        return array_map(static fn ($entry): int => (int) $entry['id'], wpmcp_test_funnelkit_offer_list($upsell));
    }

    public function test_the_ops_sit_on_the_theme_pair_and_need_funnelkit_and_its_upsell_module(): void
    {
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $op) {
            $this->assertArrayHasKey($op, $ops);
        }
        $this->assertSame('write', $ops['create-funnelkit-upsell-offer']['mode']);
        $this->assertSame('write', $ops['update-funnelkit-upsell-offer']['mode']);
        $this->assertSame('write', $ops['set-funnelkit-upsell-offer-products']['mode']);
        $this->assertSame('destructive', $ops['delete-funnelkit-upsell-offer']['mode']);

        [ , $upsell ] = $this->upsell();

        remove_all_filters('wpmcp_funnelkit_offers_active');
        add_filter('wpmcp_funnelkit_offers_active', '__return_false');
        $out = $this->write('create-funnelkit-upsell-offer', [ 'step_id' => $upsell, 'title' => 'X' ]);
        $this->assertSame('funnelkit_pro_inactive', $this->code($out));
        $this->assertStringContainsString('Funnel Builder Pro', (string) $out['error']['message']);

        remove_all_filters('wpmcp_funnelkit_active');
        add_filter('wpmcp_funnelkit_active', '__return_false');
        $this->assertSame('funnelkit_inactive', $this->code($this->write('create-funnelkit-upsell-offer', [ 'step_id' => $upsell, 'title' => 'X' ])));
    }

    public function test_the_snapshot_covers_the_offer_posts_an_upsell_step_lists(): void
    {
        [ $funnel, $upsell ] = $this->upsell();
        $offer = $this->offer($upsell);

        $this->assertContains($offer, FunnelKit_Funnel_Snapshot::step_ids((array) FunnelKit_Funnel_Snapshot::row($funnel)));
        $posts = array_map(static fn (array $entry): int => (int) $entry['post']['ID'], $this->state($funnel)['posts']);
        $this->assertContains($offer, $posts);
    }

    public function test_create_adds_an_offer_to_the_upsell_step_previews_with_dry_run_and_rolls_back(): void
    {
        [ $funnel, $upsell ] = $this->upsell();
        $before = $this->state($funnel);

        $dry = $this->ok('create-funnelkit-upsell-offer', [ 'step_id' => $upsell, 'title' => 'Extra case', 'dry_run' => true ]);
        $this->assertTrue($dry['result']['dry_run']);
        $this->assertSame(1, $dry['result']['would_create']['position']);
        $this->assertFalse($dry['recoverable']);
        $this->assertSame($before, $this->state($funnel));

        $out   = $this->ok('create-funnelkit-upsell-offer', [ 'step_id' => $upsell, 'title' => 'Extra case', 'type' => 'downsell' ]);
        $offer = (int) $out['result']['offer']['offer_id'];
        $this->assertTrue($out['recoverable']);
        $this->assertSame('wfocu_offer', get_post_type($offer));
        $this->assertSame('Extra case', $out['result']['offer']['title']);
        $this->assertSame('downsell', $out['result']['offer']['type']);
        $this->assertSame('active', $out['result']['offer']['status']);
        $this->assertSame($upsell, $out['result']['offer']['step_id']);
        $this->assertSame($funnel, $out['result']['offer']['funnel_id']);
        $this->assertSame([ $offer ], $this->listed($upsell));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($funnel));
        $this->assertNull(get_post($offer));
        $this->assertSame([], $this->listed($upsell));
    }

    public function test_create_refuses_a_step_that_is_not_an_upsell_step_in_a_funnel(): void
    {
        [ $funnel ] = $this->upsell();
        $checkout   = (int) $this->ok('add-funnelkit-step', [ 'funnel_id' => $funnel, 'type' => 'wc_checkout', 'title' => 'Second' ])['result']['step_id'];
        $loose      = self::factory()->post->create([ 'post_type' => 'wfocu_funnel' ]);
        $before     = $this->state($funnel);

        $this->assertSame('upsell_step_not_found', $this->code($this->write('create-funnelkit-upsell-offer', [ 'step_id' => $checkout, 'title' => 'X' ])));
        $this->assertSame('upsell_step_not_in_funnel', $this->code($this->write('create-funnelkit-upsell-offer', [ 'step_id' => $loose, 'title' => 'X' ])));
        $this->assertSame($before, $this->state($funnel));
    }

    public function test_update_renames_and_switches_an_offer_and_rolls_back(): void
    {
        [ $funnel, $upsell ] = $this->upsell();
        $offer  = $this->offer($upsell);
        $before = $this->state($funnel);

        $dry = $this->ok('update-funnelkit-upsell-offer', [ 'offer_id' => $offer, 'title' => 'Two cases', 'enabled' => false, 'dry_run' => true ]);
        $this->assertSame([ 'Extra case', 'Two cases' ], $dry['result']['changes']['title']);
        $this->assertSame([ 'active', 'inactive' ], $dry['result']['changes']['status']);
        $this->assertSame($before, $this->state($funnel));

        $this->assertSame('nothing_to_update', $this->code($this->write('update-funnelkit-upsell-offer', [ 'offer_id' => $offer ])));

        $out = $this->ok('update-funnelkit-upsell-offer', [ 'offer_id' => $offer, 'title' => 'Two cases', 'enabled' => false ]);
        $this->assertSame('Two cases', $out['result']['offer']['title']);
        $this->assertSame('inactive', $out['result']['offer']['status']);
        $this->assertSame('draft', get_post_status($offer));
        $this->assertSame('0', (string) wpmcp_test_funnelkit_offer_list($upsell)[0]['state']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($funnel));
        $this->assertSame('publish', get_post_status($offer));
        $this->assertSame('Extra case', get_the_title($offer));
    }

    public function test_products_replace_the_offer_list_report_the_price_and_roll_back_exactly(): void
    {
        [ $funnel, $upsell ] = $this->upsell();
        $offer  = $this->offer($upsell);
        $case   = $this->product('20', '16', 'Case');
        $strap  = $this->product('10', '', 'Strap');
        $GLOBALS['wpmcp_test_wfocu_addons'] = [ 'MultiProduct' ];
        $before = $this->state($funnel);

        $lines = [
            [ 'product_id' => $case, 'quantity' => 2, 'discount_type' => 'percentage_on_sale', 'discount_amount' => 25 ],
            [ 'product_id' => $strap, 'discount_type' => 'fixed_on_reg', 'discount_amount' => 3 ],
        ];
        $dry = $this->ok('set-funnelkit-upsell-offer-products', [ 'offer_id' => $offer, 'products' => $lines, 'dry_run' => true ]);
        $this->assertSame($before, $this->state($funnel));
        $by = array_column($dry['result']['products'], null, 'product_id');
        $this->assertEqualsWithDelta(12.0, $by[ $case ]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(24.0, $by[ $case ]['line_total'], 0.001);
        $this->assertEqualsWithDelta(7.0, $by[ $strap ]['unit_price'], 0.001);

        $out     = $this->ok('set-funnelkit-upsell-offer-products', [ 'offer_id' => $offer, 'products' => $lines ]);
        $setting = get_post_meta($offer, '_wfocu_setting', true);
        $this->assertIsObject($setting);
        $keys = array_flip(array_map('intval', get_object_vars($setting->products)));
        $this->assertArrayHasKey($case, $keys);
        $this->assertSame(2, (int) $setting->fields->{$keys[ $case ]}->quantity);
        $this->assertSame('percentage_on_sale', $setting->fields->{$keys[ $case ]}->discount_type);
        $this->assertSame(2, (int) $setting->have_multiple_product);
        $this->assertSame('', $setting->template, 'The offer design is kept.');

        $key   = array_column($out['result']['products'], null, 'product_id')[ $case ]['key'];
        $again = $this->ok('set-funnelkit-upsell-offer-products', [ 'offer_id' => $offer, 'products' => [ [ 'product_id' => $case, 'discount_type' => 'fixed_on_reg', 'discount_amount' => 5 ] ] ]);
        $this->assertSame([ $strap ], $again['result']['removed']);
        $by = array_column($again['result']['products'], null, 'product_id');
        $this->assertSame([ $case ], array_keys($by));
        $this->assertSame($key, $by[ $case ]['key'], 'A product already on the offer keeps its key.');
        $this->assertSame(2, $by[ $case ]['quantity'], 'Fields not passed are kept.');
        $this->assertEqualsWithDelta(15.0, $by[ $case ]['unit_price'], 0.001);

        Rollback_Service::restore_operation($again['operation_id']);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($funnel));
    }

    public function test_product_refusals_write_nothing(): void
    {
        [ $funnel, $upsell ] = $this->upsell();
        $offer  = $this->offer($upsell);
        $case   = $this->product('20');
        $strap  = $this->product('10');
        $before = $this->state($funnel);

        $this->assertSame('multiple_products_need_addon', $this->code($this->write('set-funnelkit-upsell-offer-products', [ 'offer_id' => $offer, 'products' => [ [ 'product_id' => $case ], [ 'product_id' => $strap ] ] ])));
        $this->assertSame('product_not_found', $this->code($this->write('set-funnelkit-upsell-offer-products', [ 'offer_id' => $offer, 'products' => [ [ 'product_id' => 999999 ] ] ])));
        $this->assertSame('duplicate_product', $this->code($this->write('set-funnelkit-upsell-offer-products', [ 'offer_id' => $offer, 'products' => [ [ 'product_id' => $case ], [ 'product_id' => $case ] ] ])));
        $this->assertSame('invalid_discount', $this->code($this->write('set-funnelkit-upsell-offer-products', [ 'offer_id' => $offer, 'products' => [ [ 'product_id' => $case, 'discount_type' => 'percentage_on_reg', 'discount_amount' => 150 ] ] ])));
        $this->assertSame('offer_not_found', $this->code($this->write('set-funnelkit-upsell-offer-products', [ 'offer_id' => $case, 'products' => [ [ 'product_id' => $case ] ] ])));
        $this->assertSame($before, $this->state($funnel));
    }

    public function test_delete_needs_confirm_and_rollback_brings_the_offer_back_in_place(): void
    {
        [ $funnel, $upsell ] = $this->upsell();
        $first  = $this->offer($upsell, 'Extra case');
        $second = $this->offer($upsell, 'Strap', 'downsell');
        $this->ok('set-funnelkit-upsell-offer-products', [ 'offer_id' => $first, 'products' => [ [ 'product_id' => $this->product('20') ] ] ]);
        $before = $this->state($funnel);

        $this->assertSame('confirmation_required', $this->code($this->write('delete-funnelkit-upsell-offer', [ 'offer_id' => $first ])));
        $out = $this->ok('delete-funnelkit-upsell-offer', [ 'offer_id' => $first ], [ 'confirm' => true ]);
        $this->assertTrue($out['result']['deleted']);
        $this->assertNull(get_post($first));
        $this->assertSame([ $second ], $this->listed($upsell));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($funnel));
        $this->assertSame([ $first, $second ], $this->listed($upsell));
        $this->assertSame((string) $upsell, (string) get_post_meta($first, '_funnel_id', true));
    }

    public function test_an_undo_keeps_an_offer_added_since_and_does_not_list_one_deleted_since(): void
    {
        [ $funnel, $upsell ] = $this->upsell();
        $kept  = $this->offer($upsell, 'Kept');
        $gone  = $this->offer($upsell, 'Gone');
        $title = $this->ok('update-funnelkit-upsell-offer', [ 'offer_id' => $kept, 'title' => 'Renamed' ]);
        $later = $this->offer($upsell, 'Later');
        $this->ok('delete-funnelkit-upsell-offer', [ 'offer_id' => $gone ], [ 'confirm' => true ]);

        $this->assertTrue(Rollback_Service::restore_operation($title['operation_id']));
        $warnings = Rollback_Service::take_warnings();
        $this->assertSame('Kept', get_the_title($kept));
        $this->assertSame('wfocu_offer', get_post_type($later));
        $this->assertNull(get_post($gone));
        $this->assertSame([ $kept, $later ], $this->listed($upsell));
        $this->assertStringContainsString((string) $later, implode(' ', $warnings));
    }

    public function test_funnelkits_own_access_check_is_honoured(): void
    {
        [ , $upsell ] = $this->upsell();
        $offer   = $this->offer($upsell);
        $manager = self::factory()->user->create([ 'role' => 'subscriber' ]);
        get_userdata($manager)->add_cap('manage_woocommerce');
        get_userdata($manager)->add_cap('edit_theme_options');
        wp_set_current_user($manager);
        $before = count(Snapshot_Store::list_by_session('default'));

        $this->assertSame('funnelkit_access_denied', $this->code($this->write('update-funnelkit-upsell-offer', [ 'offer_id' => $offer, 'title' => 'Hijack' ])));
        $this->assertSame('Extra case', get_the_title($offer));
        $this->assertCount($before, Snapshot_Store::list_by_session('default'));
    }
}
