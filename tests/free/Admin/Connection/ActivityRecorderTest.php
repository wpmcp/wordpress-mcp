<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Admin\Connection;

use WPMCP\Admin\Connection\Activity_Recorder;
use WPMCP\Admin\Connection\Mcp_Client;

/**
 * What WP MCP keeps about each application password the MCP route answered
 * (issue #1258): when it first and last answered a request made with it, and
 * the name the client gave. Every answered request counts, so a 2026-07-28
 * client that never sends initialize is recorded as surely as a 2025 one.
 * Writes stay cheap: a new password or a newly named client is written at
 * once, a known one at most once a minute. Pure: no WordPress, so it runs in
 * the fast tier.
 */
class ActivityRecorderTest extends \WP_UnitTestCase
{
    private const USER = 7;
    private const UUID = '3b241101-e2bb-4255-8caf-4136c566a962';
    private const NOW  = 1_790_000_000;

    public function test_the_first_answered_request_is_recorded_at_once(): void
    {
        $store = new Fake_Activity_Store();

        (new Activity_Recorder($store))->record(self::USER, self::UUID, Mcp_Client::of('claude-code', '2.1.330'), self::NOW);

        $activity = $store->find(self::USER, self::UUID);
        $this->assertNotNull($activity);
        $this->assertSame('claude-code 2.1.330', $activity->client->label());
        $this->assertSame(self::NOW, $activity->first_at);
        $this->assertSame(self::NOW, $activity->last_at);
        $this->assertNull($store->find(self::USER, 'another-password'), 'kept per password');
        $this->assertNull($store->find(self::USER + 1, self::UUID), 'kept per user');
    }

    public function test_a_client_that_sends_no_name_is_still_recorded(): void
    {
        $store = new Fake_Activity_Store();

        (new Activity_Recorder($store))->record(self::USER, self::UUID, Mcp_Client::unnamed(), self::NOW);

        $activity = $store->find(self::USER, self::UUID);
        $this->assertNotNull($activity);
        $this->assertFalse($activity->client->is_named());
    }

    public function test_the_time_of_a_known_client_is_written_at_most_once_a_minute(): void
    {
        $store    = new Fake_Activity_Store();
        $recorder = new Activity_Recorder($store);
        $client   = Mcp_Client::of('claude-code', '2.1.330');

        $recorder->record(self::USER, self::UUID, $client, self::NOW);
        $recorder->record(self::USER, self::UUID, $client, self::NOW + 5);
        $recorder->record(self::USER, self::UUID, $client, self::NOW + Activity_Recorder::INTERVAL - 1);
        $this->assertSame(1, $store->saves(), 'requests within the minute write nothing');
        $this->assertSame(self::NOW, $store->find(self::USER, self::UUID)?->last_at);

        $recorder->record(self::USER, self::UUID, $client, self::NOW + Activity_Recorder::INTERVAL);
        $this->assertSame(2, $store->saves());
        $activity = $store->find(self::USER, self::UUID);
        $this->assertSame(self::NOW, $activity?->first_at, 'the first request stays the first');
        $this->assertSame(self::NOW + Activity_Recorder::INTERVAL, $activity?->last_at);
    }

    public function test_a_client_that_names_itself_differently_is_written_at_once(): void
    {
        $store    = new Fake_Activity_Store();
        $recorder = new Activity_Recorder($store);

        $recorder->record(self::USER, self::UUID, Mcp_Client::unnamed(), self::NOW);
        $recorder->record(self::USER, self::UUID, Mcp_Client::of('claude-code', '2.1.330'), self::NOW + 2);
        $recorder->record(self::USER, self::UUID, Mcp_Client::of('claude-code', '2.1.331'), self::NOW + 4);

        $this->assertSame(3, $store->saves());
        $this->assertSame('claude-code 2.1.331', $store->find(self::USER, self::UUID)?->client->label());
        $this->assertSame(self::NOW, $store->find(self::USER, self::UUID)?->first_at);
    }

    /**
     * A 2025 client names itself only in initialize; its later requests
     * ride the session and name nobody, so they keep the name.
     */
    public function test_an_unnamed_request_keeps_the_name_the_client_gave_before(): void
    {
        $store    = new Fake_Activity_Store();
        $recorder = new Activity_Recorder($store);

        $recorder->record(self::USER, self::UUID, Mcp_Client::of('cursor', '1.7.2'), self::NOW);
        $recorder->record(self::USER, self::UUID, Mcp_Client::unnamed(), self::NOW + Activity_Recorder::INTERVAL + 1);

        $this->assertSame('cursor 1.7.2', $store->find(self::USER, self::UUID)?->client->label());
        $this->assertSame(self::NOW + Activity_Recorder::INTERVAL + 1, $store->find(self::USER, self::UUID)?->last_at);
    }

    public function test_a_clock_that_goes_back_never_moves_the_last_request_back(): void
    {
        $store    = new Fake_Activity_Store();
        $recorder = new Activity_Recorder($store);
        $client   = Mcp_Client::of('claude-code', '2.1.330');

        $recorder->record(self::USER, self::UUID, $client, self::NOW);
        $recorder->record(self::USER, self::UUID, $client, self::NOW - 600);

        $this->assertSame(1, $store->saves());
        $this->assertSame(self::NOW, $store->find(self::USER, self::UUID)?->last_at);
    }

    public function test_nothing_is_recorded_without_a_user_or_a_password(): void
    {
        $store    = new Fake_Activity_Store();
        $recorder = new Activity_Recorder($store);

        $recorder->record(0, self::UUID, Mcp_Client::of('claude-code', '2.1.330'), self::NOW);
        $recorder->record(self::USER, '', Mcp_Client::of('claude-code', '2.1.330'), self::NOW);

        $this->assertSame(0, $store->saves());
    }
}
