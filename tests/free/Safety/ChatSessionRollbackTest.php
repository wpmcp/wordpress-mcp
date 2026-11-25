<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Governance\Governance;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tools\Governance\Update_Governance_Settings;
use WPMCP\Tools\Migration\Find_Replace_Content;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\Settings\Update_Settings;

/**
 * Settings, governance and find-and-replace writes join the chat's session
 * (issue #1255): given a session_id they record under it, so
 * rollback-session for that chat undoes them and leaves another chat's
 * writes alone. Without one, update-settings and update-governance-settings
 * keep recording under 'default', and find-replace-content keeps making
 * and returning a session of its own.
 */
final class ChatSessionRollbackTest extends \WP_UnitTestCase
{
    private const OPTIONS = [ 'blogname', 'blogdescription' ];

    /** @var array<string, mixed> */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Governance::reset_for_tests();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        foreach (self::OPTIONS as $option) {
            $this->original[ $option ] = get_option($option);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $option => $value) {
            update_option($option, $value);
        }
        Governance::reset_for_tests();
        parent::tearDown();
    }

    public function test_a_settings_change_rolls_back_with_its_chat_only(): void
    {
        $this->assert_advertised('wpmcp/update-settings');

        (new Update_Settings())->handle([ 'settings' => [ 'blogname' => 'Chat A title' ], 'session_id' => 'chat-a' ]);
        (new Update_Settings())->handle([ 'settings' => [ 'blogdescription' => 'Chat B tagline' ], 'session_id' => 'chat-b' ]);

        $undo = (new Rollback_Session())->handle([ 'session_id' => 'chat-a' ]);

        $this->assertGreaterThan(0, $undo['restored_count'], 'rollback-session for chat A found none of its writes');
        $this->assertSame($this->original['blogname'], get_option('blogname'));
        $this->assertSame('Chat B tagline', get_option('blogdescription'));
    }

    public function test_a_settings_change_without_a_session_still_records_under_default(): void
    {
        $before = count(Snapshot_Store::list_by_session('default'));

        (new Update_Settings())->handle([ 'settings' => [ 'blogname' => 'No session' ] ]);

        $this->assertCount($before + 1, Snapshot_Store::list_by_session('default'));
    }

    public function test_a_governance_change_rolls_back_with_its_chat_only(): void
    {
        $this->assert_advertised('wpmcp/update-governance-settings');

        (new Update_Governance_Settings())->handle([ 'ability' => [ 'wpmcp/delete-post' => false ], 'session_id' => 'chat-a' ]);
        (new Update_Settings())->handle([ 'settings' => [ 'blogdescription' => 'Chat B tagline' ], 'session_id' => 'chat-b' ]);
        $this->assertFalse(Governance::ability_toggles()['wpmcp/delete-post'] ?? true);

        $undo = (new Rollback_Session())->handle([ 'session_id' => 'chat-a' ]);

        $this->assertGreaterThan(0, $undo['restored_count'], 'rollback-session for chat A found none of its writes');
        $this->assertArrayNotHasKey('wpmcp/delete-post', Governance::ability_toggles(), 'Chat A\'s tool toggle is undone');
        $this->assertSame('Chat B tagline', get_option('blogdescription'));
    }

    public function test_find_and_replace_given_a_session_joins_it_and_returns_it(): void
    {
        $this->assert_advertised('wpmcp/find-replace-content');
        $post    = $this->post('Acme Corp makes things.');
        $content = (string) get_post_field('post_content', $post);

        (new Update_Settings())->handle([ 'settings' => [ 'blogname' => 'Chat A title' ], 'session_id' => 'chat-a' ]);
        $out = (new Find_Replace_Content())->handle([ 'search' => 'Acme Corp', 'replace' => 'Acme Inc', 'dry_run' => false, 'session_id' => 'chat-a' ]);
        (new Update_Settings())->handle([ 'settings' => [ 'blogdescription' => 'Chat B tagline' ], 'session_id' => 'chat-b' ]);

        $this->assertSame('chat-a', $out['session_id'], 'The pass reports the session it recorded under');
        $this->assertCount(1, $out['applied']);

        (new Rollback_Session())->handle([ 'session_id' => 'chat-a' ]);

        clean_post_cache($post);
        $this->assertSame($content, (string) get_post_field('post_content', $post), 'The pass is undone with its chat');
        $this->assertSame($this->original['blogname'], get_option('blogname'), 'So is the chat\'s earlier write');
        $this->assertSame('Chat B tagline', get_option('blogdescription'), 'Chat B is untouched');
    }

    public function test_find_and_replace_without_a_session_makes_and_returns_its_own(): void
    {
        $post    = $this->post('Acme Corp makes things.');
        $content = (string) get_post_field('post_content', $post);

        $out = (new Find_Replace_Content())->handle([ 'search' => 'Acme Corp', 'replace' => 'Acme Inc', 'dry_run' => false ]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $out['session_id']);
        $this->assertCount(1, Snapshot_Store::list_by_session((string) $out['session_id']));

        (new Rollback_Session())->handle([ 'session_id' => (string) $out['session_id'] ]);

        clean_post_cache($post);
        $this->assertSame($content, (string) get_post_field('post_content', $post));
    }

    private function post(string $content): int
    {
        return (int) self::factory()->post->create([ 'post_content' => $content, 'post_status' => 'publish' ]);
    }

    /** The tool's input schema declares session_id, so a client sends it. */
    private function assert_advertised(string $name): void
    {
        foreach (RegisteredAbilities::all() as $ability) {
            if ($ability->name === $name) {
                $properties = $ability->input_schema['properties'] ?? [];
                $this->assertIsArray($properties);
                $this->assertArrayHasKey('session_id', $properties, $name . ' does not declare session_id');
                return;
            }
        }
        $this->fail($name . ' is not registered');
    }
}
