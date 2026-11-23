<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Meta;

use WPMCP\Safety\Post_Write_Refused;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Free\Safety\Kses\Without_Unfiltered_Html;
use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Meta\Set_Post_Meta;
use WPMCP\Tools\Migration\Find_Replace_Content;

/**
 * PHP serialized data in a meta value: WordPress unserializes a stored
 * value that is serialized data when it reads it, and find-replace writes
 * its result raw, so a string that becomes serialized data is rebuilt as
 * whatever it describes, objects included. For a user without
 * unfiltered_html (on a network, a site administrator who is not a super
 * admin), set-post-meta, the meta argument of create-post and update-post,
 * and find-replace-content refuse a value that would store serialized data
 * the stored value did not already hold in the same shape, with
 * unfiltered_html_required (403). Arrays passed as arrays go through the
 * normal API path as before, and a user with unfiltered_html writes any
 * value.
 */
final class SerializedMetaTest extends \WP_UnitTestCase
{
    use Without_Unfiltered_Html;

    private const OBJECT = 'O:8:"stdClass":1:{s:4:"name";s:4:"Acme";}';

    private const ARRAY = 'a:1:{s:4:"name";s:4:"Acme";}';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    private function log_in_with_unfiltered_html(): void
    {
        remove_filter('map_meta_cap', [self::class, 'deny_unfiltered_html'], 10);
        $id = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($id);
        }
        wp_set_current_user($id);
        $this->assertTrue(current_user_can('unfiltered_html'));
    }

    private function raw(int $post_id, string $key): ?string
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key));

        return null === $value ? null : (string) $value;
    }

    private function assert_refused(callable $write): void
    {
        try {
            $write();
        } catch (Post_Write_Refused $e) {
            $this->assert_kses_refused($e->error());
            $this->assertSame(403, $e->error()->get_error_data()['status']);
            $this->assertStringContainsString('serialized', $e->error()->get_error_message());
            return;
        }
        $this->fail('A value holding new serialized data was written.');
    }

    /** @return array<string, array{0: string}> */
    public static function serialized_strings(): array
    {
        return ['object' => [self::OBJECT], 'array' => [self::ARRAY], 'integer' => ['i:7;']];
    }

    /** @dataProvider serialized_strings */
    public function test_set_post_meta_refuses_a_serialized_string(string $value): void
    {
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'company', 'Acme');
        $this->log_in_without_unfiltered_html();

        $this->assert_refused(fn () => (new Set_Post_Meta())->handle(['post_id' => $post_id, 'key' => 'company', 'value' => $value]));

        $this->assertSame('Acme', $this->raw($post_id, 'company'));
        $this->assertSame(0, Snapshot_Store::row_count());
    }

    public function test_serialized_data_the_row_already_holds_in_the_same_shape_is_kept(): void
    {
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'company', ['name' => 'Acme']);
        $this->log_in_without_unfiltered_html();

        (new Set_Post_Meta())->handle(['post_id' => $post_id, 'key' => 'company', 'value' => 'a:1:{s:4:"name";s:7:"Acme UK";}']);

        $this->assertSame('a:1:{s:4:"name";s:7:"Acme UK";}', get_post_meta($post_id, 'company', true));
    }

    public function test_create_and_update_post_refuse_serialized_data_but_take_arrays(): void
    {
        $post_id = self::factory()->post->create();
        $this->log_in_without_unfiltered_html();
        $drafts = (int) wp_count_posts('post')->draft;

        $this->assert_refused(fn () => (new Create_Post())->handle(['title' => 'X', 'meta' => ['company' => self::OBJECT]]));
        $this->assert_refused(fn () => (new Update_Post())->handle(['post_id' => $post_id, 'meta' => ['company' => self::OBJECT]]));
        $this->assert_refused(fn () => (new Update_Post())->handle(['post_id' => $post_id, 'meta' => ['company' => ['nested' => self::OBJECT]]]));
        wp_cache_flush();
        $this->assertSame($drafts, (int) wp_count_posts('post')->draft);
        $this->assertFalse(metadata_exists('post', $post_id, 'company'));

        (new Update_Post())->handle(['post_id' => $post_id, 'meta' => ['company' => ['name' => 'Acme', 'staff' => 7]]]);
        $this->assertSame(['name' => 'Acme', 'staff' => 7], get_post_meta($post_id, 'company', true));
    }

    public function test_find_replace_refuses_a_replacement_that_makes_serialized_data(): void
    {
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'company', 'NAME_HERE');
        update_post_meta($post_id, 'profile', ['name' => 'NAME_HERE', 'staff' => 7]);
        $this->log_in_without_unfiltered_html();
        $raw_profile = $this->raw($post_id, 'profile');

        foreach (['company', 'profile'] as $key) {
            $this->assert_refused(fn () => (new Find_Replace_Content())->handle(['search' => 'NAME_HERE', 'replace' => self::OBJECT, 'fields' => ['meta'], 'meta_keys' => [$key], 'dry_run' => false]));
        }
        $this->assertSame('NAME_HERE', $this->raw($post_id, 'company'));
        $this->assertSame($raw_profile, $this->raw($post_id, 'profile'));

        // Text replaced inside stored serialized data keeps its shape.
        $out = (new Find_Replace_Content())->handle(['search' => 'NAME_HERE', 'replace' => 'Acme', 'fields' => ['meta'], 'meta_keys' => ['profile'], 'dry_run' => false]);
        $this->assertCount(1, $out['applied']);
        wp_cache_delete($post_id, 'post_meta');
        $this->assertSame(['name' => 'Acme', 'staff' => 7], get_post_meta($post_id, 'profile', true));
    }

    public function test_a_user_with_unfiltered_html_writes_any_value(): void
    {
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'company', 'NAME_HERE');
        $this->log_in_with_unfiltered_html();

        (new Set_Post_Meta())->handle(['post_id' => $post_id, 'key' => 'note', 'value' => self::ARRAY]);
        $this->assertSame(self::ARRAY, get_post_meta($post_id, 'note', true));

        $out = (new Find_Replace_Content())->handle(['search' => 'NAME_HERE', 'replace' => self::ARRAY, 'fields' => ['meta'], 'meta_keys' => ['company'], 'dry_run' => false]);
        $this->assertCount(1, $out['applied']);
    }
}
