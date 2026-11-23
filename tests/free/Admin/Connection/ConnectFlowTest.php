<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Admin\Connection;

use WPMCP\Admin\Connection\Blocker;
use WPMCP\Admin\Connection\Client_App;
use WPMCP\Admin\Connection\Connect_Flow;
use WPMCP\Admin\Connection\Flow_Input;
use WPMCP\Admin\Connection\Sign_In;
use WPMCP\Admin\Connection\Step_State;

/**
 * The Connection screen's four steps (pick the app, create the connection,
 * add it to the app, check it) and the state each one is in, for every case
 * the screen draws. Pure: no WordPress, so it runs in the fast tier.
 */
class ConnectFlowTest extends \WP_UnitTestCase
{
    /** @param array<string, mixed> $over */
    private function input(?Client_App $app, array $over = []): Flow_Input
    {
        return new Flow_Input(
            app: $app,
            connector: (bool) ($over['connector'] ?? false),
            created: (bool) ($over['created'] ?? false),
            verified: (bool) ($over['verified'] ?? false),
            passwords_unavailable: isset($over['passwords_unavailable']) ? (string) $over['passwords_unavailable'] : null,
            oauth_on: (bool) ($over['oauth_on'] ?? false),
            oauth_locked: (bool) ($over['oauth_locked'] ?? false),
            public_address: (bool) ($over['public_address'] ?? true),
        );
    }

    /** @return Step_State[] */
    private function states(Flow_Input $in): array
    {
        $model = (new Connect_Flow())->model($in);

        return [$model->pick, $model->create, $model->add, $model->check];
    }

    public function test_the_first_visit_opens_on_the_app_picker(): void
    {
        $model = (new Connect_Flow())->model($this->input(null));

        $this->assertSame([Step_State::Current, Step_State::Locked, Step_State::Locked, Step_State::Locked], $this->states($this->input(null)));
        $this->assertNull($model->blocker);
        $this->assertNull($model->sign_in);
    }

    public function test_a_password_app_waits_for_create_then_opens_steps_three_and_four(): void
    {
        foreach ([Client_App::ClaudeCode, Client_App::ClaudeDesktop, Client_App::Cursor, Client_App::Antigravity, Client_App::Other] as $app) {
            $this->assertSame(
                [Step_State::Done, Step_State::Current, Step_State::Locked, Step_State::Locked],
                $this->states($this->input($app)),
                $app->value . ' before the password exists'
            );
            $this->assertSame(
                [Step_State::Done, Step_State::Done, Step_State::Current, Step_State::Open],
                $this->states($this->input($app, ['created' => true])),
                $app->value . ' after the password is created'
            );
            $this->assertSame(
                [Step_State::Done, Step_State::Done, Step_State::Done, Step_State::Done],
                $this->states($this->input($app, ['created' => true, 'verified' => true])),
                $app->value . ' once the check saw it'
            );
            $this->assertSame(Sign_In::Password, (new Connect_Flow())->model($this->input($app))->sign_in);
        }
    }

    public function test_only_one_step_is_ever_the_current_one(): void
    {
        foreach ([null, Client_App::ClaudeCode, Client_App::Chatgpt] as $app) {
            foreach ([[], ['created' => true], ['oauth_on' => true], ['created' => true, 'verified' => true]] as $over) {
                $current = array_filter($this->states($this->input($app, $over)), static fn (Step_State $s): bool => Step_State::Current === $s);
                $this->assertLessThanOrEqual(1, count($current));
            }
        }
    }

    public function test_no_https_blocks_step_two_for_password_apps(): void
    {
        $model = (new Connect_Flow())->model($this->input(Client_App::ClaudeCode, ['passwords_unavailable' => 'https_required', 'created' => true]));

        $this->assertSame([Step_State::Done, Step_State::Blocked, Step_State::Locked, Step_State::Locked], [$model->pick, $model->create, $model->add, $model->check]);
        $this->assertSame(Blocker::HttpsRequired, $model->blocker);
    }

    public function test_passwords_switched_off_by_a_plugin_block_step_two(): void
    {
        foreach (['disabled_by_plugin', 'disabled'] as $reason) {
            $model = (new Connect_Flow())->model($this->input(Client_App::Cursor, ['passwords_unavailable' => $reason]));

            $this->assertSame(Step_State::Blocked, $model->create);
            $this->assertSame(Blocker::PasswordsDisabled, $model->blocker);
        }
    }

    public function test_an_oauth_app_asks_for_oauth_and_needs_no_password(): void
    {
        $off = (new Connect_Flow())->model($this->input(Client_App::Chatgpt));
        $this->assertSame(Sign_In::Oauth, $off->sign_in);
        $this->assertSame([Step_State::Done, Step_State::Current, Step_State::Locked, Step_State::Locked], $this->states($this->input(Client_App::Chatgpt)));
        $this->assertNull($off->blocker);

        $this->assertSame(
            [Step_State::Done, Step_State::Done, Step_State::Current, Step_State::Open],
            $this->states($this->input(Client_App::Chatgpt, ['oauth_on' => true])),
            'OAuth already on: step 2 is done without a password'
        );

        // Passwords being unavailable does not matter to an OAuth app.
        $this->assertSame(
            Step_State::Done,
            (new Connect_Flow())->model($this->input(Client_App::Chatgpt, ['oauth_on' => true, 'passwords_unavailable' => 'disabled_by_plugin']))->create
        );
    }

    public function test_the_claude_desktop_connector_route_signs_in_with_oauth(): void
    {
        $model = (new Connect_Flow())->model($this->input(Client_App::ClaudeDesktop, ['connector' => true, 'oauth_on' => true]));

        $this->assertSame(Sign_In::Oauth, $model->sign_in);
        $this->assertSame(Step_State::Done, $model->create);
        $this->assertSame(Step_State::Current, $model->add);
    }

    public function test_a_cloud_app_on_a_site_that_is_not_public_is_blocked(): void
    {
        $model = (new Connect_Flow())->model($this->input(Client_App::Chatgpt, ['public_address' => false, 'oauth_on' => true]));

        $this->assertSame(Step_State::Blocked, $model->create);
        $this->assertSame(Blocker::NotPublic, $model->blocker);
        $this->assertSame(Step_State::Locked, $model->add);

        // An app on the same computer is not affected by the address.
        $this->assertNull((new Connect_Flow())->model($this->input(Client_App::ClaudeCode, ['public_address' => false]))->blocker);
    }

    public function test_oauth_fixed_off_in_code_blocks_an_oauth_app(): void
    {
        $model = (new Connect_Flow())->model($this->input(Client_App::Chatgpt, ['oauth_locked' => true]));

        $this->assertSame(Step_State::Blocked, $model->create);
        $this->assertSame(Blocker::OauthLocked, $model->blocker);

        // Fixed on is not a block.
        $this->assertNull((new Connect_Flow())->model($this->input(Client_App::Chatgpt, ['oauth_locked' => true, 'oauth_on' => true]))->blocker);
    }

    public function test_client_apps_come_from_request_values_and_reject_anything_else(): void
    {
        $this->assertSame(Client_App::ClaudeCode, Client_App::from_request('claude-code'));
        $this->assertSame(Client_App::Antigravity, Client_App::from_request('antigravity'));
        $this->assertNull(Client_App::from_request(''));
        $this->assertNull(Client_App::from_request('gemini-cli'));
        $this->assertNull(Client_App::from_request('<script>'));
        $this->assertCount(6, Client_App::cases());
    }

    public function test_oauth_clients_are_matched_to_their_app_by_redirect_host_or_name(): void
    {
        $this->assertTrue(Client_App::Chatgpt->matches_oauth_client('ChatGPT', ['chatgpt.com']));
        $this->assertTrue(Client_App::Chatgpt->matches_oauth_client('', ['chatgpt.com']));
        $this->assertTrue(Client_App::Chatgpt->matches_oauth_client('OpenAI connector', []));
        $this->assertTrue(Client_App::ClaudeDesktop->matches_oauth_client('Claude', ['claude.ai']));
        $this->assertTrue(Client_App::ClaudeDesktop->matches_oauth_client('', ['www.claude.com']));
        $this->assertFalse(Client_App::ClaudeDesktop->matches_oauth_client('ChatGPT', ['chatgpt.com']));
        $this->assertFalse(Client_App::Chatgpt->matches_oauth_client('Claude', ['claude.ai']));
        $this->assertFalse(Client_App::Chatgpt->matches_oauth_client('Unnamed', ['127.0.0.1']));
        $this->assertFalse(Client_App::Chatgpt->matches_oauth_client('', ['notchatgpt.com.evil.example']));
        $this->assertFalse(Client_App::Chatgpt->matches_oauth_client('', ['notchatgpt.com']), 'hosts match on a label boundary');
        $this->assertFalse(Client_App::Cursor->matches_oauth_client('Cursor', ['cursor.com']), 'password apps never match an OAuth client');
    }
}
