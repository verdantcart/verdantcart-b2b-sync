<?php

namespace Tests\Feature;

use App\Erp\Fortnox\Events\Connection;
use App\Erp\Fortnox\Events\ConnectionClosed;
use App\Erp\Fortnox\Events\Connector;
use App\Erp\Fortnox\Events\ListenerStatus;
use App\Erp\Fortnox\Events\OffsetStore;
use App\Erp\Fortnox\FortnoxClient;
use App\Events\FortnoxEventReceived;
use App\Models\ErpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class FortnoxListenTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<FakeFortnoxSocket> one per connection the listener opened */
    private array $sockets = [];

    /** @var list<callable(FakeFortnoxSocket): void> what each successive connection does */
    private array $scripts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Event::fake([FortnoxEventReceived::class]);
        config([
            'erp.driver' => 'fortnox',
            'erp.fortnox.client_id' => 'client-123',
            'erp.fortnox.client_secret' => 'secret-456',
            'erp.fortnox.events.topics' => 'articles',
        ]);
        $this->app->forgetInstance(FortnoxClient::class);
        ErpConnection::create([
            'driver' => 'fortnox', 'access_token' => 'access-1', 'refresh_token' => 'refresh-1',
            'expires_at' => now()->addHour(), 'connected_at' => now(),
        ]);

        $test = $this;
        $this->app->instance(Connector::class, new class($test) extends Connector
        {
            public function __construct(private readonly FortnoxListenTest $test) {}

            public function connect(string $url, float $timeout = 10): Connection
            {
                return $this->test->nextSocket();
            }
        });
    }

    public function nextSocket(): FakeFortnoxSocket
    {
        $script = array_shift($this->scripts) ?? fn (FakeFortnoxSocket $s) => $s->dropAfterEvents();
        $socket = new FakeFortnoxSocket($script);

        return $this->sockets[] = $socket;
    }

    private function event(string $offset, string $entity, string $topic = 'articles', int $tenant = 4242): array
    {
        return ['topic' => $topic, 'offset' => $offset, 'type' => 'article-updated-v1', 'tenantId' => $tenant, 'entityId' => $entity, 'timestamp' => '2026-11-20T10:00:00+01:00'];
    }

    public function test_it_subscribes_and_turns_each_change_into_an_event(): void
    {
        $this->scripts[] = fn (FakeFortnoxSocket $s) => $s->events([$this->event('o1', 'KAF1'), $this->event('o2', 'KAF2')]);

        $this->artisan('fortnox:listen', ['--max-events' => 2])
            ->expectsOutputToContain('Listening to articles for Fortnox company 4242')
            ->expectsOutputToContain('Stopped after 2 events')
            ->assertSuccessful();

        $sent = $this->sockets[0]->sent;
        $this->assertSame(['add-tenants-v1', 'add-topics-v1', 'subscribe-v1'], array_column($sent, 'command'));
        $this->assertSame(['secret-456', ['Bearer access-1']], [$sent[0]['clientSecret'], $sent[0]['accessTokens']]);
        $this->assertSame([['topic' => 'articles']], $sent[1]['topics']);

        Event::assertDispatched(FortnoxEventReceived::class, fn ($e) => $e->event->entityId === 'KAF1' && $e->event->action() === 'updated');
        Event::assertDispatchedTimes(FortnoxEventReceived::class, 2);
        $this->assertSame(['articles' => 'o2'], app(OffsetStore::class)->all());
        $this->assertSame([ListenerStatus::CONNECTED, 4242], [ListenerStatus::get()['state'], ListenerStatus::get()['tenant_id']]);
        $this->assertTrue($this->sockets[0]->closed);
    }

    public function test_after_a_dropped_line_it_resumes_from_the_last_offset_and_skips_the_replayed_one(): void
    {
        $this->scripts[] = fn (FakeFortnoxSocket $s) => $s->events([$this->event('o1', 'KAF1'), $this->event('o2', 'KAF2')])->dropAfterEvents();
        $this->scripts[] = fn (FakeFortnoxSocket $s) => $s->events([$this->event('o2', 'KAF2'), $this->event('o3', 'KAF3')]);

        $this->artisan('fortnox:listen', ['--max-events' => 3])->assertSuccessful();

        $this->assertSame([['topic' => 'articles', 'offset' => 'o2']], $this->sockets[1]->sent[1]['topics']);
        Event::assertDispatchedTimes(FortnoxEventReceived::class, 3); // KAF2 once, not twice
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($d) => $d->totalSeconds === 5.0);
    }

    public function test_events_for_another_company_are_ignored(): void
    {
        $this->scripts[] = fn (FakeFortnoxSocket $s) => $s->events([$this->event('x1', 'OTHER', tenant: 999), $this->event('o1', 'KAF1')]);

        $this->artisan('fortnox:listen', ['--max-events' => 1])->assertSuccessful();

        Event::assertDispatched(FortnoxEventReceived::class, fn ($e) => $e->event->entityId === 'KAF1');
        Event::assertNotDispatched(FortnoxEventReceived::class, fn ($e) => $e->event->entityId === 'OTHER');
    }

    public function test_a_topic_without_scope_is_dropped_and_the_rest_keep_working(): void
    {
        $this->scripts[] = fn (FakeFortnoxSocket $s) => $s->refuseTopics(['warehouse-stockbalances']);
        $this->scripts[] = fn (FakeFortnoxSocket $s) => $s->events([$this->event('o1', 'KAF1')]);

        $this->artisan('fortnox:listen', ['--topics' => 'articles,warehouse-stockbalances', '--max-events' => 1])
            ->expectsOutputToContain('Fortnox refused the topics: warehouse-stockbalances')
            ->assertSuccessful();

        $this->assertTrue($this->sockets[0]->closed, 'the refused connection is closed, not leaked');
        $this->assertSame([['topic' => 'articles']], $this->sockets[1]->sent[1]['topics']);
        Sleep::assertNeverSlept();
    }

    public function test_a_refused_token_is_refreshed_once_then_reported(): void
    {
        Http::fake(['apps.fortnox.se/oauth-v1/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600])]);
        $this->scripts[] = fn (FakeFortnoxSocket $s) => $s->refuseToken();
        $this->scripts[] = fn (FakeFortnoxSocket $s) => $s->refuseToken();

        $this->artisan('fortnox:listen')
            ->expectsOutputToContain('Log in to Fortnox again on the setup page')
            ->assertFailed();

        $this->assertSame(['Bearer access-2'], $this->sockets[1]->sent[0]['accessTokens'], 'second try uses a refreshed token');
        $this->assertSame(ListenerStatus::FAILED, ListenerStatus::get()['state']);
    }

    public function test_it_reconnects_with_a_fresh_token_before_the_old_one_expires(): void
    {
        Http::fake(['apps.fortnox.se/oauth-v1/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600])]);
        // Token valid for an hour; a quiet hour passes on the first connection.
        $this->scripts[] = fn (FakeFortnoxSocket $s) => $s->events([])->whenIdle(fn () => $this->travel(59)->minutes());
        $this->scripts[] = fn (FakeFortnoxSocket $s) => $s->events([$this->event('o1', 'KAF1')]);

        $this->artisan('fortnox:listen', ['--max-events' => 1])
            ->expectsOutputToContain('Renewing the Fortnox token')
            ->assertSuccessful();

        $this->assertSame(['Bearer access-1'], $this->sockets[0]->sent[0]['accessTokens']);
        $this->assertSame(['Bearer access-2'], $this->sockets[1]->sent[0]['accessTokens'], 'refreshed before it ran out, not after');
        $this->assertTrue($this->sockets[0]->closed);
        Sleep::assertNeverSlept();
    }

    public function test_an_expired_fortnox_login_stops_the_listener_instead_of_retrying_forever(): void
    {
        Http::fake(['apps.fortnox.se/oauth-v1/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid refresh token'], 400)]);
        ErpConnection::query()->update(['expires_at' => now()->subMinute()]);

        $this->artisan('fortnox:listen')
            ->expectsOutputToContain('log in to Fortnox again on the setup page')
            ->assertFailed();

        $this->assertSame([], $this->sockets, 'no connection attempted without a valid token');
        $this->assertSame(ListenerStatus::FAILED, ListenerStatus::get()['state']);
        Sleep::assertNeverSlept();
    }

    public function test_it_needs_a_fortnox_login(): void
    {
        ErpConnection::query()->delete();

        $this->artisan('fortnox:listen')->expectsOutputToContain('Log in to Fortnox on the setup page first')->assertFailed();
        $this->assertSame([], $this->sockets);
    }
}

/** A scripted stand-in for Fortnox's event stream. */
class FakeFortnoxSocket implements Connection
{
    /** @var list<array> commands the listener sent */
    public array $sent = [];

    public bool $closed = false;

    /** @var list<string|ConnectionClosed> messages waiting to be received */
    private array $inbox = [];

    private array $events = [];

    private bool $drop = false;

    private array $refusedTopics = [];

    private bool $refusedToken = false;

    /** @var null|callable(): void called once when nothing is left to receive */
    private $whenIdle = null;

    public function __construct(callable $script)
    {
        $script($this);
    }

    public function events(array $events): self
    {
        $this->events = $events;

        return $this;
    }

    public function whenIdle(callable $callback): self
    {
        $this->whenIdle = $callback;

        return $this;
    }

    public function dropAfterEvents(): self
    {
        $this->drop = true;

        return $this;
    }

    public function refuseTopics(array $topics): self
    {
        $this->refusedTopics = $topics;

        return $this;
    }

    public function refuseToken(): self
    {
        $this->refusedToken = true;

        return $this;
    }

    public function send(string $text): void
    {
        $command = json_decode($text, true);
        $this->sent[] = $command;

        $this->inbox[] = json_encode(match ($command['command']) {
            'add-tenants-v1' => $this->refusedToken
                ? ['response' => 'add-tenants-v1', 'result' => 'error', 'invalidTokens' => $command['accessTokens']]
                : ['response' => 'add-tenants-v1', 'result' => 'ok', 'tenantIds' => [$command['accessTokens'][0] => 4242]],
            'add-topics-v1' => $this->refusedTopics
                ? ['response' => 'add-topics-v1', 'result' => 'error', 'invalidTopics' => $this->refusedTopics]
                : ['response' => 'add-topics-v1', 'result' => 'ok'],
            'subscribe-v1' => ['response' => 'subscribe-v1', 'result' => 'ok'],
        });

        if ($command['command'] === 'subscribe-v1') {
            foreach ($this->events as $event) {
                $this->inbox[] = json_encode($event);
            }
            if ($this->drop) {
                $this->inbox[] = new ConnectionClosed('The connection was lost');
            }
        }
    }

    public function receive(float $timeout): ?string
    {
        $next = array_shift($this->inbox);
        if ($next === null && $this->whenIdle !== null) {
            ($this->whenIdle)();
            $this->whenIdle = null;
        }
        if ($next instanceof ConnectionClosed) {
            throw $next;
        }

        return $next;
    }

    public function ping(): void {}

    public function close(): void
    {
        $this->closed = true;
    }
}
