<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Admin\Connection;

use WPMCP\Admin\Connection\Mcp_Activity;
use WPMCP\Admin\Connection\Mcp_Client;
use WPMCP\Admin\Connection\Wp_Activity_Store;
use WPMCP\Data_Registry;

/**
 * Where the MCP requests answered per application password are kept (issue
 * #1258): user meta of the password's user, one map per site (user meta is
 * network-wide, connections are per site), at most MAX_ROWS passwords, and
 * covered by uninstall through the wpmcp_ prefix.
 */
class WpActivityStoreTest extends \WP_UnitTestCase
{
    private int $user = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = self::factory()->user->create([ 'role' => 'administrator' ]);
    }

    private function key(?int $blog_id = null): string
    {
        return is_multisite() ? Wp_Activity_Store::META . '_' . ($blog_id ?? get_current_blog_id()) : Wp_Activity_Store::META;
    }

    public function test_it_keeps_each_password_apart_in_the_user_meta_of_this_site(): void
    {
        $store = new Wp_Activity_Store();
        $store->save($this->user, 'uuid-a', new Mcp_Activity(Mcp_Client::of('claude-code', '2.1.330'), 100, 160));
        $store->save($this->user, 'uuid-b', new Mcp_Activity(Mcp_Client::unnamed(), 200, 200));

        $a = $store->find($this->user, 'uuid-a');
        $this->assertNotNull($a);
        $this->assertSame('claude-code 2.1.330', $a->client->label());
        $this->assertSame([ 100, 160 ], [ $a->first_at, $a->last_at ]);
        $this->assertFalse($store->find($this->user, 'uuid-b')?->client->is_named());
        $this->assertNull($store->find($this->user, 'uuid-c'));
        $this->assertNull($store->find(self::factory()->user->create(), 'uuid-a'), 'per user');

        $stored = get_user_meta($this->user, $this->key(), true);
        $this->assertIsArray($stored);
        $this->assertSame([ 'uuid-a', 'uuid-b' ], array_keys($stored));
        $this->assertSame([ 'first_at' => 100, 'last_at' => 160, 'name' => 'claude-code', 'version' => '2.1.330' ], $stored['uuid-a']);
        $this->assertTrue(Data_Registry::owns_user_meta($this->key()), 'uninstall deletes it with the wpmcp_ user meta');
    }

    public function test_forget_drops_one_password_and_the_meta_with_the_last(): void
    {
        $store = new Wp_Activity_Store();
        $store->save($this->user, 'uuid-a', new Mcp_Activity(Mcp_Client::of('cursor', '1.7.2'), 100, 100));
        $store->save($this->user, 'uuid-b', new Mcp_Activity(Mcp_Client::of('cursor', '1.7.2'), 100, 100));

        $store->forget($this->user, 'uuid-a');
        $this->assertNull($store->find($this->user, 'uuid-a'));
        $this->assertNotNull($store->find($this->user, 'uuid-b'));

        $store->forget($this->user, 'uuid-b');
        $store->forget($this->user, 'never-recorded');
        $this->assertSame('', get_user_meta($this->user, $this->key(), true));
    }

    public function test_rows_of_another_shape_read_as_nothing(): void
    {
        update_user_meta($this->user, $this->key(), [
            'uuid-a' => [ 'first_at' => '100', 'last_at' => 160 ],
            'uuid-b' => 'not a row',
            'uuid-c' => [ 'first_at' => 100, 'last_at' => 160, 'name' => [ 'x' ], 'version' => 2 ],
            7        => [ 'first_at' => 100, 'last_at' => 160 ],
        ]);
        $store = new Wp_Activity_Store();

        $this->assertNull($store->find($this->user, 'uuid-a'));
        $this->assertNull($store->find($this->user, 'uuid-b'));
        $this->assertFalse($store->find($this->user, 'uuid-c')?->client->is_named(), 'a name that is not text reads as no name');
        $this->assertNull($store->find($this->user, '7'));
    }

    public function test_it_keeps_the_most_recently_active_passwords_up_to_its_cap(): void
    {
        $store = new Wp_Activity_Store();
        for ($i = 0; $i <= Wp_Activity_Store::MAX_ROWS; $i++) {
            $store->save($this->user, 'uuid-' . $i, new Mcp_Activity(Mcp_Client::of('app', (string) $i), 1000 + $i, 1000 + $i));
        }

        $this->assertNull($store->find($this->user, 'uuid-0'), 'the least recently active goes');
        $this->assertNotNull($store->find($this->user, 'uuid-1'));
        $this->assertNotNull($store->find($this->user, 'uuid-' . Wp_Activity_Store::MAX_ROWS));
        $this->assertCount(Wp_Activity_Store::MAX_ROWS, (array) get_user_meta($this->user, $this->key(), true));
    }

    public function test_nothing_is_written_without_a_user_or_a_password(): void
    {
        $store = new Wp_Activity_Store();
        $store->save(0, 'uuid-a', new Mcp_Activity(Mcp_Client::unnamed(), 1, 1));
        $store->save($this->user, '', new Mcp_Activity(Mcp_Client::unnamed(), 1, 1));

        $this->assertSame('', get_user_meta($this->user, $this->key(), true));
        $this->assertNull($store->find(0, 'uuid-a'));
    }

    public function test_another_site_of_a_network_keeps_its_own(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Per-site keys exist only on multisite.');
        }

        update_user_meta($this->user, $this->key(get_current_blog_id() + 999), [
            'uuid-a' => [ 'first_at' => 100, 'last_at' => 160, 'name' => 'cursor', 'version' => '1.0' ],
        ]);

        $this->assertNull((new Wp_Activity_Store())->find($this->user, 'uuid-a'));
    }
}
