<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Block_Suite;
use WPMCP\Integrations\Block_Suite_GreenShift_Css;
use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Block suites, GreenShift (issue #1009) through the block-suites
 * dispatcher pair.
 *
 * GreenShift registers its blocks from block.json files that declare no
 * attributes (they live in its editor script). Each block keeps an id of
 * gsbp- plus the first seven characters of the editor's client id, carries
 * it in its markup classes and keys its own CSS on it in the
 * inlineCssStyles attribute, which GreenShift prints at render time. On
 * save its editor also posts the page's CSS to the _gspb_post_css post meta,
 * which GreenShift enqueues in the head; nothing on the server rebuilds it,
 * so a write rebuilds it from the blocks the way GreenShift's renderer
 * collects their CSS, in document order. A rollback restores the meta with
 * the post snapshot, byte for byte.
 *
 * GreenShift is not installed in the test core: its blocks are stood in by
 * registering them the way it does (no attributes) and presence is toggled
 * through the wpmcp_block_suite_active filter.
 */
class BlockSuitesGreenShiftTest extends \WP_UnitTestCase
{
    private const META = '_gspb_post_css';

    /** @var array<string,bool> suite slug => active */
    private array $active = [ 'greenshift' => true ];

    /** @var callable */
    private $presence;

    /** @var string[] */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->presence = fn ($default, string $suite): bool => $this->active[ $suite ] ?? false;
        add_filter('wpmcp_block_suite_active', $this->presence, 10, 2);

        $this->register('greenshift-blocks/heading');
        $this->register('greenshift-blocks/container');
        $this->register('greenshift-blocks/element');
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_block_suite_active', $this->presence, 10);
        foreach ($this->registered as $name) {
            if (\WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                unregister_block_type($name);
            }
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function register(string $name): void
    {
        register_block_type($name, [ 'title' => $name, 'attributes' => [] ]);
        $this->registered[] = $name;
    }

    private function read(string $op, array $args = []): array
    {
        return ( new Block_Suites_Integration() )->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return ( new Block_Suites_Integration() )->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    /** @return array{0:int,1:string} */
    private function post(string $content = ''): array
    {
        $id = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => $content ]);
        return [ $id, hash('sha256', (string) get_post($id)->post_content) ];
    }

    private function content(int $id): string
    {
        clean_post_cache($id);
        return (string) get_post($id)->post_content;
    }

    private function blocks(int $id): array
    {
        return array_values(array_filter(parse_blocks($this->content($id)), static fn ($b) => null !== $b['blockName']));
    }

    private function snapshots(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name()); // phpcs:ignore WordPress.DB
    }

    /** A GreenShift heading as its editor saves it. */
    private function heading(string $id = '__UNIQUE_ID__', string $color = 'red'): string
    {
        return sprintf(
            '<!-- wp:greenshift-blocks/heading {"id":"%1$s","inlineCssStyles":".gspb_heading-id-%1$s{color:%2$s;}","localId":"%1$s"} --><h2 id="gspb_heading-id-%1$s" class="gspb_heading gspb_heading-id-%1$s">Hi</h2><!-- /wp:greenshift-blocks/heading -->',
            $id,
            $color
        );
    }

    private function container(string $id, string $inner): string
    {
        return sprintf(
            '<!-- wp:greenshift-blocks/container {"id":"%1$s","inlineCssStyles":".gspb_container-id-%1$s{padding:10px;}"} --><div id="gspb_container-id-%1$s" class="gspb_container gspb_container-id-%1$s">%2$s</div><!-- /wp:greenshift-blocks/container -->',
            $id,
            $inner
        );
    }

    // ------------------------------------------------------------ surface

    public function test_list_suites_reports_greenshift(): void
    {
        $by = array_column($this->read('list-suites')['result']['suites'], null, 'suite');

        $this->assertArrayHasKey('greenshift', $by);
        $this->assertSame('GreenShift', $by['greenshift']['label']);
        $this->assertSame('id', $by['greenshift']['unique_id_attribute']);
        $this->assertSame('greenshift-blocks/', $by['greenshift']['namespace']);
        $this->assertSame('per_post_cache', $by['greenshift']['css_model']);
        $this->assertTrue($by['greenshift']['active']);
        $this->assertNull($by['greenshift']['version'], 'GreenShift defines no version constant');
    }

    public function test_greenshift_is_detected_by_its_dir_url_constant(): void
    {
        $this->assertSame('GREENSHIFT_DIR_URL', Block_Suite::spec('greenshift')['constant']);
        $this->assertTrue(( new Block_Suites_Integration() )->should_register());
        $this->active = [];
        $this->assertFalse(( new Block_Suites_Integration() )->should_register());
    }

    public function test_schemas_add_the_id_and_allow_editor_attributes(): void
    {
        $out = $this->read('get-block-schemas', [ 'suite' => 'greenshift' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $by = array_column($out['result']['blocks'], null, 'name');

        $this->assertContains('id', $by['greenshift-blocks/heading']['attributes']);
        $this->assertSame('allowed', $out['result']['unknown_attributes']);
    }

    // ------------------------------------------------------------ writes

    public function test_insert_generates_a_gsbp_id_swaps_it_into_markup_and_css_and_rebuilds_the_post_css(): void
    {
        [ $id, $hash ] = $this->post($this->heading('gsbp-aaaaaaa', 'blue'));
        update_post_meta($id, self::META, '.stale{color:black;}');

        $out = $this->write('insert-block', [
            'suite'         => 'greenshift',
            'id'            => $id,
            'expected_hash' => $hash,
            'path'          => [ 1 ],
            'markup'        => $this->heading(),
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $node = $this->blocks($id)[1];
        $uid  = (string) $node['attrs']['id'];
        $this->assertMatchesRegularExpression('/^gsbp-[0-9a-f]{7}$/', $uid);
        $this->assertSame('.gspb_heading-id-' . $uid . '{color:red;}', $node['attrs']['inlineCssStyles'], 'the placeholder is swapped in the block css');
        $this->assertSame($uid, $node['attrs']['localId']);
        $this->assertStringContainsString('gspb_heading-id-' . $uid . '"', $node['innerHTML']);
        $this->assertStringNotContainsString('__UNIQUE_ID__', $this->content($id));

        $this->assertSame('per_post_cache', $out['result']['css']['model']);
        $this->assertTrue($out['result']['css']['refreshed']);
        $this->assertSame(
            '.gspb_heading-id-gsbp-aaaaaaa{color:blue;}.gspb_heading-id-' . $uid . '{color:red;}',
            get_post_meta($id, self::META, true),
            'the head stylesheet is rebuilt from every block, in document order'
        );
    }

    public function test_nested_blocks_are_collected_in_document_order_and_a_duplicate_id_is_rewritten_in_its_css(): void
    {
        [ $id, $hash ] = $this->post($this->heading('gsbp-aaaaaaa'));

        $out = $this->write('insert-block', [
            'suite'         => 'greenshift',
            'id'            => $id,
            'expected_hash' => $hash,
            'path'          => [ 0 ],
            'markup'        => $this->container('gsbp-ccccccc', $this->heading('gsbp-aaaaaaa', 'green')),
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $blocks = $this->blocks($id);
        $inner  = $blocks[0]['innerBlocks'][0];
        $uid    = (string) $inner['attrs']['id'];
        $this->assertNotSame('gsbp-aaaaaaa', $uid, 'an id already used in the post is replaced');
        $this->assertSame('.gspb_heading-id-' . $uid . '{color:green;}', $inner['attrs']['inlineCssStyles']);
        $this->assertSame($uid, $inner['attrs']['localId']);
        $this->assertSame(
            '.gspb_container-id-gsbp-ccccccc{padding:10px;}.gspb_heading-id-' . $uid . '{color:green;}.gspb_heading-id-gsbp-aaaaaaa{color:red;}',
            get_post_meta($id, self::META, true)
        );
    }

    public function test_update_rebuilds_the_post_css_from_the_changed_block(): void
    {
        [ $id, $hash ] = $this->post($this->heading('gsbp-aaaaaaa'));
        update_post_meta($id, self::META, '.gspb_heading-id-gsbp-aaaaaaa{color:red;}');

        $out = $this->write('update-block', [
            'suite' => 'greenshift', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'attrs' => [ 'inlineCssStyles' => '.gspb_heading-id-gsbp-aaaaaaa{color:purple;}' ],
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame('.gspb_heading-id-gsbp-aaaaaaa{color:purple;}', get_post_meta($id, self::META, true));
    }

    public function test_write_and_rollback_restore_the_exact_content_and_post_css(): void
    {
        [ $id, $hash ] = $this->post($this->heading('gsbp-aaaaaaa'));
        $original      = $this->content($id);
        update_post_meta($id, self::META, '.gspb_heading-id-gsbp-aaaaaaa{color:red;}');

        $out = $this->write('insert-block', [ 'suite' => 'greenshift', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 1 ], 'markup' => $this->heading() ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertNotSame('.gspb_heading-id-gsbp-aaaaaaa{color:red;}', get_post_meta($id, self::META, true));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame($original, $this->content($id), 'rollback restores the exact bytes');
        $this->assertSame([ '.gspb_heading-id-gsbp-aaaaaaa{color:red;}' ], get_post_meta($id, self::META), 'and the post css of the restored blocks');
    }

    public function test_rollback_of_a_first_greenshift_block_removes_the_post_css(): void
    {
        [ $id, $hash ] = $this->post('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->');
        $original      = $this->content($id);

        $out = $this->write('insert-block', [ 'suite' => 'greenshift', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'markup' => $this->heading() ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertNotSame('', get_post_meta($id, self::META, true));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame($original, $this->content($id));
        $this->assertSame([], get_post_meta($id, self::META), 'no greenshift block, no post css');
    }

    public function test_rollback_restores_editor_css_the_blocks_cannot_rebuild(): void
    {
        [ $id, $hash ] = $this->post($this->heading('gsbp-aaaaaaa'));
        $original      = $this->content($id);
        // What the editor saved: more than the blocks' inlineCssStyles hold.
        $editor_css = '.gspb_heading-id-gsbp-aaaaaaa{color:red;}.gs-local-class{margin:4px;}:root{--swiper-theme-color:#007aff}';
        update_post_meta($id, self::META, $editor_css);

        $out = $this->write('insert-block', [ 'suite' => 'greenshift', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 1 ], 'markup' => $this->heading() ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertStringNotContainsString('gs-local-class', (string) get_post_meta($id, self::META, true), 'the write rebuilt the meta from the blocks');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame($original, $this->content($id));
        $this->assertSame([ $editor_css ], get_post_meta($id, self::META), 'the rollback keeps the editor css exactly as it was');
    }

    public function test_css_render_blocks_contribute_their_local_classes(): void
    {
        [ $id, $hash ] = $this->post('');
        $markup        = '<!-- wp:greenshift-blocks/element {"id":"gsbp-ddddddd","localId":"gsbp-ddddddd","CSSRender":"1","inlineCssStyles":".ignored{}","dynamicGClasses":[{"value":"card","type":"local","label":"card","css":".card{padding:8px;}","selectors":[{"value":":hover","css":".card:hover{color:teal;}"},{"value":" a"}]},{"value":"btn","type":"preset","css":".btn{}"}]} --><div class="gsbp-ddddddd card">x</div><!-- /wp:greenshift-blocks/element -->';

        $out = $this->write('insert-block', [ 'suite' => 'greenshift', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'markup' => $markup ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame('.card{padding:8px;}.card:hover{color:teal;}', get_post_meta($id, self::META, true), 'as the renderer: local classes and their selectors, not inlineCssStyles or presets');
    }

    public function test_style_attributes_render_through_greenshift_or_leave_the_meta_untouched(): void
    {
        $blocks = parse_blocks('<!-- wp:greenshift-blocks/heading {"inlineCssStyles":".a{color:red;}"} --><h2>a</h2><!-- /wp:greenshift-blocks/heading --><!-- wp:greenshift-blocks/element {"localId":"gsbp-eeeeeee","CSSRender":"1","styleAttributes":{"color":["blue"]},"enableSpecificity":true} --><div class="gsbp-eeeeeee">x</div><!-- /wp:greenshift-blocks/element -->');

        $calls  = [];
        $render = static function ($attributes, string $selector, string $prefix, $specificity) use (&$calls): string {
            $calls[] = [ $attributes, $selector, $prefix, $specificity ];
            return $selector . '{color:' . $attributes['color'][0] . ';}';
        };
        $this->assertSame('.a{color:red;}.gsbp-eeeeeee{color:blue;}', ( new Block_Suite_GreenShift_Css($render) )->css_for($blocks));
        $this->assertSame([ [ [ 'color' => [ 'blue' ] ], '.gsbp-eeeeeee', '', true ] ], $calls);
        $this->assertNull(( new Block_Suite_GreenShift_Css() )->css_for($blocks), 'without GreenShift\'s renderer the css cannot be built');

        // Through a write, with GreenShift's renderer absent here: the stored meta stays.
        $this->assertFalse(function_exists('gspb_render_style_attributes'));
        [ $id, $hash ] = $this->post('');
        update_post_meta($id, self::META, '.from-editor{}');
        $out = $this->write('insert-block', [
            'suite' => 'greenshift', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => '<!-- wp:greenshift-blocks/element {"id":"gsbp-eeeeeee","localId":"gsbp-eeeeeee","CSSRender":"1","styleAttributes":{"color":["blue"]}} --><div class="gsbp-eeeeeee">x</div><!-- /wp:greenshift-blocks/element -->',
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame([ '.from-editor{}' ], get_post_meta($id, self::META), 'no partial rebuild');
    }

    public function test_the_rebuild_strips_tags_so_the_css_cannot_close_its_style_element(): void
    {
        $css = new Block_Suite_GreenShift_Css();
        $this->assertSame(
            '.a{color:red;}.b{color:blue;}',
            $css->css_for(parse_blocks('<!-- wp:greenshift-blocks/heading {"inlineCssStyles":".a{color:red;}</style><script>x()</script>"} --><h2>a</h2><!-- /wp:greenshift-blocks/heading --><!-- wp:paragraph {"inlineCssStyles":".z{}"} --><p>z</p><!-- /wp:paragraph --><!-- wp:greenshift-blocks/heading {"inlineCssStyles":".b{color:blue;}"} --><h2>b</h2><!-- /wp:greenshift-blocks/heading -->'))
        );
    }

    public function test_an_inactive_greenshift_leaves_the_post_css_alone(): void
    {
        [ $id, ] = $this->post($this->heading('gsbp-aaaaaaa'));
        update_post_meta($id, self::META, '.kept{}');
        $this->active['greenshift'] = false;
        $this->active['spectra']    = true; // keep the pair registered

        $out = $this->read('get-block-schemas', [ 'suite' => 'greenshift' ]);
        $this->assertSame('suite_unavailable', $out['error']['code']);

        Block_Suite::refresh_after_restore($id);
        $this->assertSame('.kept{}', get_post_meta($id, self::META, true));
    }

    public function test_a_refused_write_takes_no_snapshot(): void
    {
        [ $id, $hash ] = $this->post($this->heading('gsbp-aaaaaaa'));
        $before        = $this->snapshots();

        $out = $this->write('insert-block', [ 'suite' => 'greenshift', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'markup' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ]);
        $this->assertSame('unknown_suite_block', $out['error']['code'] ?? null);
        $this->assertSame($before, $this->snapshots());
    }
}
