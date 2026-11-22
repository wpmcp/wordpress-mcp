<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Block_Suite;
use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Block suites, Essential Blocks (issue #1009) through the block-suites
 * dispatcher pair.
 *
 * Essential Blocks registers its blocks with no server-side attributes. Its
 * editor generates each block's CSS and saves it in the block's own
 * blockMeta attribute (desktop, tab and mobile), keyed by its blockId; its
 * StyleHandler gathers those into uploads/eb-style/eb-style-{id}.min.css
 * (and frontend/frontend-{id}.min.css) on the next view when the file is
 * missing, and drops them on save_post. The server cannot compute blockMeta,
 * so a write that adds an Essential Blocks block, or changes more than its
 * text, must carry blockMeta or is refused before anything is written; after
 * a write or rollback the generated files go, so they are rebuilt from the
 * blocks as they now are.
 *
 * Essential Blocks is not installed in the test core: its blocks are stood
 * in by registering them the way it does (no attributes) and presence is
 * toggled through the wpmcp_block_suite_active filter.
 */
class BlockSuitesEssentialBlocksTest extends \WP_UnitTestCase
{
    /** @var array<string,bool> suite slug => active */
    private array $active = [ 'essential-blocks' => true ];

    /** @var callable */
    private $presence;

    /** @var string[] */
    private array $registered = [];

    /** @var string[] */
    private array $patterns = [];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->presence = fn ($default, string $suite): bool => $this->active[ $suite ] ?? false;
        add_filter('wpmcp_block_suite_active', $this->presence, 10, 2);

        $this->register('essential-blocks/advanced-heading');
        $this->register('essential-blocks/advanced-tabs');
        $this->register('essential-blocks/tab');
        $this->dir = wp_upload_dir(null, false)['basedir'] . '/eb-style/';
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_block_suite_active', $this->presence, 10);
        foreach ($this->registered as $name) {
            if (\WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                unregister_block_type($name);
            }
        }
        foreach ($this->patterns as $name) {
            unregister_block_pattern($name);
        }
        foreach (array_merge((array) glob($this->dir . '*.css'), (array) glob($this->dir . '*/*.css')) as $file) {
            if (is_string($file) && is_file($file)) {
                wp_delete_file($file);
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

    private function pattern(string $name, string $content): void
    {
        register_block_pattern($name, [ 'title' => $name, 'content' => $content ]);
        $this->patterns[] = $name;
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

    /** An Essential Blocks heading as its editor saves it; $meta false leaves blockMeta out. */
    private function heading(string $bid = '__UNIQUE_ID__', bool $meta = true, string $color = '#ff0000'): string
    {
        $attrs = [ 'blockId' => $bid, 'titleText' => 'Hi', 'titleColor' => $color ];
        if ($meta) {
            $attrs['blockMeta'] = [ 'desktop' => '.' . $bid . '.eb-advance-heading-wrapper .eb-ah-title{color:' . $color . '}', 'tab' => '', 'mobile' => '' ];
        }
        return sprintf(
            '<!-- wp:essential-blocks/advanced-heading %1$s --><div class="wp-block-essential-blocks-advanced-heading"><div class="eb-parent-wrapper eb-parent-%2$s"><div class="%2$s eb-advance-heading-wrapper" data-id="%2$s"><h2 class="eb-ah-title">Hi</h2></div></div></div><!-- /wp:essential-blocks/advanced-heading -->',
            wp_json_encode($attrs, JSON_UNESCAPED_SLASHES),
            $bid
        );
    }

    /** Seed the files and markers Essential Blocks generates for a post. */
    private function seed_assets(int $id): array
    {
        $files = [ $this->dir . 'eb-style-' . $id . '.min.css', $this->dir . 'frontend/frontend-' . $id . '.min.css' ];
        foreach ($files as $file) {
            wp_mkdir_p(dirname($file));
            file_put_contents($file, '.stale{}'); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        set_transient('eb_nx_nocss_' . $id, 1);
        return $files;
    }

    // ------------------------------------------------------------ surface

    public function test_list_suites_reports_essential_blocks(): void
    {
        $by = array_column($this->read('list-suites')['result']['suites'], null, 'suite');

        $this->assertArrayHasKey('essential-blocks', $by);
        $this->assertSame('Essential Blocks', $by['essential-blocks']['label']);
        $this->assertSame('blockId', $by['essential-blocks']['unique_id_attribute']);
        $this->assertSame('essential-blocks/', $by['essential-blocks']['namespace']);
        $this->assertSame('per_post_cache', $by['essential-blocks']['css_model']);
        $this->assertSame('ESSENTIAL_BLOCKS_VERSION', Block_Suite::spec('essential-blocks')['constant']);
    }

    public function test_schemas_are_read_without_the_write_guard(): void
    {
        $out = $this->read('get-block-schemas', [ 'suite' => 'essential-blocks' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $by = array_column($out['result']['blocks'], null, 'name');
        $this->assertContains('blockId', $by['essential-blocks/advanced-heading']['attributes']);
        $this->assertSame('allowed', $out['result']['unknown_attributes']);
    }

    // ------------------------------------------------------------ refusals

    public function test_insert_without_block_meta_is_refused_before_any_snapshot(): void
    {
        [ $id, $hash ] = $this->post('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->');
        $before        = $this->snapshots();
        $original      = $this->content($id);

        $out = $this->write('insert-block', [ 'suite' => 'essential-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'markup' => $this->heading('__UNIQUE_ID__', false) ]);
        $this->assertSame('editor_render_required', $out['error']['code'] ?? null, wp_json_encode($out));
        $this->assertStringContainsString('Essential Blocks editor', $out['error']['message']);
        $this->assertSame('essential-blocks/advanced-heading', $out['error']['data']['block'] ?? null);
        $this->assertSame($before, $this->snapshots());
        $this->assertSame($original, $this->content($id));
    }

    public function test_an_inner_block_without_block_meta_is_refused_too(): void
    {
        [ $id, $hash ] = $this->post('');
        $before        = $this->snapshots();
        $markup        = '<!-- wp:essential-blocks/advanced-tabs {"blockId":"eb-advanced-tabs-aaaaa","blockMeta":{"desktop":".eb-advanced-tabs-aaaaa{}"}} --><div class="eb-advanced-tabs-aaaaa">'
            . '<!-- wp:essential-blocks/tab {"blockId":"eb-tab-bbbbb","tabId":"1"} --><div class="eb-tab-bbbbb">'
            . $this->heading('eb-advance-heading-ccccc', false)
            . '</div><!-- /wp:essential-blocks/tab --></div><!-- /wp:essential-blocks/advanced-tabs -->';

        $out = $this->write('insert-block', [ 'suite' => 'essential-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'markup' => $markup ]);
        $this->assertSame('editor_render_required', $out['error']['code'] ?? null, wp_json_encode($out));
        $this->assertSame('essential-blocks/advanced-heading', $out['error']['data']['block'] ?? null, 'the tab itself generates no css');
        $this->assertSame($before, $this->snapshots());
    }

    public function test_update_of_a_style_attribute_without_block_meta_is_refused(): void
    {
        [ $id, $hash ] = $this->post($this->heading('eb-advance-heading-aaaaa'));
        $before        = $this->snapshots();
        $original      = $this->content($id);

        $out = $this->write('update-block', [
            'suite' => 'essential-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'attrs' => [ 'titleText' => 'New', 'titleColor' => '#00ff00' ],
        ]);
        $this->assertSame('editor_render_required', $out['error']['code'] ?? null, wp_json_encode($out));
        $this->assertSame([ 'titleColor' ], $out['error']['data']['attributes'] ?? null);
        $this->assertSame($before, $this->snapshots());
        $this->assertSame($original, $this->content($id));

        $out = $this->write('update-block', [
            'suite' => 'essential-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'attrs' => [ 'blockMeta' => null ],
        ]);
        $this->assertSame('editor_render_required', $out['error']['code'] ?? null, 'removing the generated css is a style change');
        $this->assertSame($before, $this->snapshots());
    }

    public function test_import_of_a_pattern_without_block_meta_is_refused_before_any_snapshot(): void
    {
        $this->pattern('wpmcp-test/eb-bare', $this->heading('eb-advance-heading-aaaaa', false));
        [ $id, $hash ] = $this->post('');
        $before        = $this->snapshots();

        $out = $this->write('import-pattern', [ 'name' => 'wpmcp-test/eb-bare', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ] ]);
        $this->assertSame('editor_render_required', $out['error']['code'] ?? null, wp_json_encode($out));
        $this->assertSame($before, $this->snapshots());
        $this->assertSame('', $this->content($id));
    }

    // ------------------------------------------------------------ writes

    public function test_insert_with_block_meta_ids_the_block_in_markup_and_meta_and_drops_the_generated_files(): void
    {
        [ $id, $hash ] = $this->post('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->');
        $files         = $this->seed_assets($id);

        $out = $this->write('insert-block', [ 'suite' => 'essential-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 1 ], 'markup' => $this->heading() ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $node = $this->blocks($id)[1];
        $bid  = (string) $node['attrs']['blockId'];
        $this->assertMatchesRegularExpression('/^eb-advanced-heading-[0-9a-f]{5}$/', $bid);
        $this->assertSame('.' . $bid . '.eb-advance-heading-wrapper .eb-ah-title{color:#ff0000}', $node['attrs']['blockMeta']['desktop'], 'the id is swapped into the generated css');
        $this->assertStringContainsString('data-id="' . $bid . '"', $node['innerHTML']);
        $this->assertStringNotContainsString('__UNIQUE_ID__', $this->content($id));

        $this->assertSame('per_post_cache', $out['result']['css']['model']);
        $this->assertTrue($out['result']['css']['refreshed']);
        foreach ($files as $file) {
            $this->assertFileDoesNotExist($file);
        }
        $this->assertFalse(get_transient('eb_nx_nocss_' . $id));
    }

    public function test_text_only_and_inner_html_updates_need_no_block_meta(): void
    {
        [ $id, $hash ] = $this->post($this->heading('eb-advance-heading-aaaaa'));

        $out = $this->write('update-block', [
            'suite' => 'essential-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'attrs' => [ 'titleText' => 'New' ],
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame('New', $this->blocks($id)[0]['attrs']['titleText']);

        $node = $this->blocks($id)[0];
        $out  = $this->write('update-block', [
            'suite' => 'essential-blocks', 'id' => $id, 'expected_hash' => $out['result']['content_hash'], 'path' => [ 0 ],
            'inner_html' => str_replace('>Hi<', '>New<', (string) $node['innerHTML']),
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertStringContainsString('>New</h2>', $this->content($id));
    }

    public function test_a_style_update_that_carries_block_meta_is_written(): void
    {
        [ $id, $hash ] = $this->post($this->heading('eb-advance-heading-aaaaa'));
        $meta          = [ 'desktop' => '.eb-advance-heading-aaaaa .eb-ah-title{color:#00ff00}', 'tab' => '', 'mobile' => '' ];

        $out = $this->write('update-block', [
            'suite' => 'essential-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'attrs' => [ 'titleColor' => '#00ff00', 'blockMeta' => $meta ],
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $node = $this->blocks($id)[0];
        $this->assertSame('#00ff00', $node['attrs']['titleColor']);
        $this->assertSame($meta, $node['attrs']['blockMeta']);
        $this->assertSame('eb-advance-heading-aaaaa', $node['attrs']['blockId']);
    }

    public function test_import_of_a_pattern_with_block_meta_is_written(): void
    {
        $this->pattern('wpmcp-test/eb-styled', $this->heading('eb-advance-heading-aaaaa'));
        [ $id, $hash ] = $this->post('');

        $out = $this->write('import-pattern', [ 'name' => 'wpmcp-test/eb-styled', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame('essential-blocks/advanced-heading', $this->blocks($id)[0]['blockName']);
        $this->assertSame([ [ 'suite' => 'essential-blocks', 'model' => 'per_post_cache', 'refreshed' => true ] ], $out['result']['css']);
    }

    public function test_write_and_rollback_restore_the_exact_content_and_meta_and_drop_the_generated_files(): void
    {
        [ $id, $hash ] = $this->post($this->heading('eb-advance-heading-aaaaa'));
        $original      = $this->content($id);
        update_post_meta($id, '_eb_reusable_block_ids', [ 12 ]);

        $out = $this->write('insert-block', [ 'suite' => 'essential-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 1 ], 'markup' => $this->heading() ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        // Essential Blocks rebuilt its files and meta on a view, then the write is undone.
        $files = $this->seed_assets($id);
        update_post_meta($id, '_eb_reusable_block_ids', []);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));

        $this->assertSame($original, $this->content($id), 'rollback restores the exact bytes');
        $this->assertSame([ [ 12 ] ], get_post_meta($id, '_eb_reusable_block_ids'), 'and the meta of the snapshot');
        foreach ($files as $file) {
            $this->assertFileDoesNotExist($file, 'the files generated for the undone content are dropped');
        }
        $this->assertFalse(get_transient('eb_nx_nocss_' . $id));
    }

    public function test_an_inactive_essential_blocks_guards_nothing_and_keeps_its_files(): void
    {
        $this->active['essential-blocks'] = false;
        $this->active['spectra']          = true; // keep the pair registered
        $this->pattern('wpmcp-test/eb-bare-inactive', $this->heading('eb-advance-heading-aaaaa', false));
        [ $id, $hash ] = $this->post('');
        $files         = $this->seed_assets($id);

        $out = $this->write('import-pattern', [ 'name' => 'wpmcp-test/eb-bare-inactive', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        Block_Suite::refresh_after_restore($id);
        foreach ($files as $file) {
            $this->assertFileExists($file);
        }
    }
}
