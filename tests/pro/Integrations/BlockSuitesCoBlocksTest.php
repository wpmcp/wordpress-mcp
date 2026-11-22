<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Block_Suite;
use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Block suites, CoBlocks (issue #1009) through the block-suites dispatcher
 * pair.
 *
 * CoBlocks registers its blocks from block.json files with their
 * attributes, styles them with classes and has no per-block unique id. Its
 * dimension controls keep editor-written CSS in the _coblocks_dimensions
 * post meta, keyed by a coblocks.id attribute the server-side schemas do not
 * declare, so its schema is lenient: editor-saved markup carrying that
 * attribute is accepted while declared attributes keep their types. A
 * write keeps the meta as it is (a removed block leaves a harmless orphan
 * entry) and a rollback restores it with the post.
 *
 * CoBlocks is not installed in the test core: its blocks are stood in by
 * registering their block.json schemas and presence is toggled through the
 * wpmcp_block_suite_active filter.
 */
class BlockSuitesCoBlocksTest extends \WP_UnitTestCase
{
    private const DIMENSIONS = '{"coblocks-row-1a2b3c":{"padding":{"paddingTop":"20px"}}}';

    /** @var array<string,bool> suite slug => active */
    private array $active = [ 'coblocks' => true ];

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

        $this->register('coblocks/row', [
            'id'      => [ 'type' => 'number' ],
            'columns' => [ 'type' => 'number' ],
            'layout'  => [ 'type' => 'string' ],
        ]);
        $this->register('coblocks/alert', [ 'content' => [ 'type' => 'string' ] ]);
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

    private function register(string $name, array $attributes): void
    {
        register_block_type($name, [ 'title' => $name, 'attributes' => $attributes ]);
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

    /** A CoBlocks row as its editor saves it, with the undeclared coblocks attribute. */
    private function row(): string
    {
        return '<!-- wp:coblocks/row {"id":1,"columns":1,"layout":"100","coblocks":{"id":"1a2b3c"}} --><div class="wp-block-coblocks-row coblocks-row-1a2b3c"><div class="wp-block-coblocks-row__inner has-no-gutter"></div></div><!-- /wp:coblocks/row -->';
    }

    private function alert(string $text = 'Careful'): string
    {
        return sprintf('<!-- wp:coblocks/alert --><div class="wp-block-coblocks-alert"><p class="wp-block-coblocks-alert__text">%s</p></div><!-- /wp:coblocks/alert -->', $text);
    }

    // ------------------------------------------------------------ surface

    public function test_list_suites_reports_coblocks_without_a_unique_id(): void
    {
        $by = array_column($this->read('list-suites')['result']['suites'], null, 'suite');

        $this->assertArrayHasKey('coblocks', $by);
        $this->assertSame('CoBlocks', $by['coblocks']['label']);
        $this->assertNull($by['coblocks']['unique_id_attribute'], 'CoBlocks blocks have no unique id');
        $this->assertSame('coblocks/', $by['coblocks']['namespace']);
        $this->assertSame('render_time', $by['coblocks']['css_model']);
        $this->assertSame('COBLOCKS_VERSION', Block_Suite::spec('coblocks')['constant']);
        $this->assertSame('COBLOCKS_VERSION', Block_Suite::spec('coblocks')['version']);
    }

    public function test_schemas_keep_the_registered_attributes_and_allow_editor_ones(): void
    {
        $out = $this->read('get-block-schemas', [ 'suite' => 'coblocks', 'name' => 'coblocks/row' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertNull($out['result']['unique_id_attribute']);
        $this->assertSame('allowed', $out['result']['unknown_attributes']);
        $attributes = $out['result']['blocks'][0]['attributes'];
        $this->assertSame([ 'id', 'columns', 'layout' ], array_slice(array_keys($attributes), 0, 3));
        $this->assertSame(array_keys((array) \WP_Block_Type_Registry::get_instance()->get_registered('coblocks/row')->attributes), array_keys($attributes), 'the registered schema, with no id attribute added');
    }

    // ------------------------------------------------------------ writes

    public function test_editor_saved_markup_is_inserted_as_written_with_no_generated_id(): void
    {
        [ $id, $hash ] = $this->post('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->');

        $out = $this->write('insert-block', [ 'suite' => 'coblocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 1 ], 'markup' => $this->row() ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame([], $out['result']['unique_ids']);
        $this->assertSame('render_time', $out['result']['css']['model']);
        $this->assertFalse($out['result']['css']['refreshed']);

        $node = $this->blocks($id)[1];
        $this->assertSame([ 'id' => 1, 'columns' => 1, 'layout' => '100', 'coblocks' => [ 'id' => '1a2b3c' ] ], $node['attrs']);
        $this->assertStringContainsString($this->row(), $this->content($id));
    }

    public function test_declared_attributes_keep_their_types(): void
    {
        [ $id, $hash ] = $this->post('');
        $before        = $this->snapshots();

        $out = $this->write('insert-block', [
            'suite' => 'coblocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => '<!-- wp:coblocks/row {"columns":"two"} --><div class="wp-block-coblocks-row"></div><!-- /wp:coblocks/row -->',
        ]);
        $this->assertSame('invalid_block_attributes', $out['error']['code'] ?? null);
        $this->assertSame($before, $this->snapshots(), 'a refusal takes no snapshot');
    }

    public function test_update_merges_attributes_and_leaves_the_dimensions_meta_alone(): void
    {
        [ $id, $hash ] = $this->post($this->row());
        update_post_meta($id, '_coblocks_dimensions', self::DIMENSIONS);

        $out = $this->write('update-block', [ 'suite' => 'coblocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'attrs' => [ 'layout' => '50-50', 'columns' => 2 ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $node = $this->blocks($id)[0];
        $this->assertSame('50-50', $node['attrs']['layout']);
        $this->assertSame([ 'id' => '1a2b3c' ], $node['attrs']['coblocks']);
        $this->assertSame(self::DIMENSIONS, get_post_meta($id, '_coblocks_dimensions', true));
    }

    public function test_write_and_rollback_restore_the_exact_content_and_dimensions_meta(): void
    {
        [ $id, $hash ] = $this->post($this->row());
        $original      = $this->content($id);
        update_post_meta($id, '_coblocks_dimensions', self::DIMENSIONS);

        $out = $this->write('insert-block', [ 'suite' => 'coblocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 1 ], 'markup' => $this->alert() ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame(self::DIMENSIONS, get_post_meta($id, '_coblocks_dimensions', true));

        // The editor saved new dimensions after the write, then it is undone.
        update_post_meta($id, '_coblocks_dimensions', '{"coblocks-row-1a2b3c":{"padding":{"paddingTop":"90px"}}}');
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame($original, $this->content($id), 'rollback restores the exact bytes');
        $this->assertSame([ self::DIMENSIONS ], get_post_meta($id, '_coblocks_dimensions'), 'and the meta with the post');
    }
}
