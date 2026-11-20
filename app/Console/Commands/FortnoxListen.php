<?php

namespace App\Console\Commands;

use App\Erp\ErpException;
use App\Erp\Fortnox\Events\ConnectionClosed;
use App\Erp\Fortnox\Events\Connector;
use App\Erp\Fortnox\Events\EventStream;
use App\Erp\Fortnox\Events\EventStreamError;
use App\Erp\Fortnox\Events\FortnoxEvent;
use App\Erp\Fortnox\Events\ListenerStatus;
use App\Erp\Fortnox\Events\OffsetStore;
use App\Erp\Fortnox\FortnoxClient;
use App\Events\FortnoxEventReceived;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Keeps one connection to Fortnox's event stream open and turns every change into a
 * FortnoxEventReceived event. Runs until stopped; reconnects by itself.
 *
 * - Resumes from the last handled offset per topic, so nothing is missed across restarts
 *   (Fortnox replays up to 14 days).
 * - Reconnects with a fresh access token a couple of minutes before the current one expires.
 * - Backs off 5, 10, 30, then 60 seconds when Fortnox or the network is away.
 */
#[Signature('fortnox:listen
    {--topics= : Comma-separated topics (default: FORTNOX_EVENT_TOPICS)}
    {--from-now : Forget stored offsets and start with new events only}
    {--max-events=0 : Stop after this many events (0 = run until stopped)}
    {--max-seconds=0 : Stop after this many seconds (0 = run until stopped)}')]
#[Description('Listen to Fortnox events in real time (articles, stock, customers, …)')]
class FortnoxListen extends Command
{
    public const BACKOFF_SECONDS = [5, 10, 30, 60];

    public const PING_EVERY_SECONDS = 30;

    /** Reconnect this long before the access token expires. */
    public const TOKEN_MARGIN_SECONDS = 120;

    private int $handled = 0;

    private float $startedAt = 0;

    public function handle(FortnoxClient $fortnox, Connector $connector, OffsetStore $offsets): int
    {
        if (config('erp.driver') !== FortnoxClient::DRIVER) {
            $this->components->error('The event stream needs ERP_DRIVER=fortnox.');

            return self::FAILURE;
        }
        if (! $fortnox->isConfigured() || $fortnox->connection() === null) {
            $this->components->error('Fortnox is not connected. Log in to Fortnox on the setup page first.');

            return self::FAILURE;
        }

        $topics = $this->topics();
        if ($this->option('from-now')) {
            $offsets->forget();
        }

        $this->startedAt = microtime(true);
        $failures = 0;
        $tokenRetried = false;

        while (! $this->limitReached()) {
            try {
                // The client refreshes a token only in its last minute; the listener renews
                // earlier, so it forces the refresh itself or it would reconnect in a loop.
                if ($fortnox->connection()->expires_at->lt(now()->addSeconds(self::TOKEN_MARGIN_SECONDS))) {
                    $fortnox->refresh(force: true);
                }
                $token = $fortnox->accessToken();
                $expiresAt = $fortnox->connection()->expires_at;

                $stream = new EventStream($connector->connect((string) config('erp.fortnox.events.url')));
                try {
                    $tenantId = $stream->start($fortnox->clientSecret(), $token, $topics, $offsets->all());
                } catch (Throwable $e) {
                    $stream->close(); // never leave a refused or half-open connection behind

                    throw $e;
                }

                ListenerStatus::connected($tenantId, $topics);
                $this->components->info('Listening to '.implode(', ', $topics)." for Fortnox company {$tenantId}.");
                $failures = 0;
                $tokenRetried = false;

                // At least a minute per connection, however short-lived a token Fortnox hands out.
                $this->listen($stream, $offsets, max(now()->getTimestamp() + 60, $expiresAt->getTimestamp() - self::TOKEN_MARGIN_SECONDS));
            } catch (EventStreamError $e) {
                if ($e->invalidTopics !== [] && array_diff($topics, $e->invalidTopics) !== []) {
                    // Keep what Fortnox accepts; say which topics need a scope.
                    $this->components->warn($e->getMessage());
                    $topics = array_values(array_diff($topics, $e->invalidTopics));

                    continue;
                }
                if ($e->invalidToken && ! $tokenRetried) {
                    $tokenRetried = true;
                    $this->safely(fn () => $fortnox->refresh(force: true));

                    continue;
                }

                ListenerStatus::failed($e->getMessage());
                Log::error('Fortnox event stream refused', ['error' => $e->getMessage()]);
                $this->components->error($e->getMessage());

                return self::FAILURE;
            } catch (ErpException $e) {
                if (! $e->retryable) {
                    // An expired login or a refused request doesn't get better by waiting.
                    ListenerStatus::failed($e->getMessage());
                    Log::error('Fortnox event stream stopped', ['error' => $e->getMessage()]);
                    $this->components->error($e->getMessage());

                    return self::FAILURE;
                }
                $this->backOff($e->getMessage(), $failures++);
            } catch (ConnectionClosed $e) {
                $this->backOff($e->getMessage(), $failures++);
            }
        }

        $this->components->info("Stopped after {$this->handled} ".str('event')->plural($this->handled).'.');

        return self::SUCCESS;
    }

    private function backOff(string $error, int $failures): void
    {
        $wait = self::BACKOFF_SECONDS[min($failures, count(self::BACKOFF_SECONDS) - 1)];
        ListenerStatus::reconnecting($error);
        Log::warning('Fortnox event stream disconnected', ['error' => $error, 'retry_in' => $wait]);
        $this->components->warn(rtrim($error, '. ')."; reconnecting in {$wait} s.");

        if (! $this->limitReached()) {
            Sleep::for($wait)->seconds();
        }
    }

    /** Receive events until the token is about to expire, the connection drops, or a limit is reached. */
    private function listen(EventStream $stream, OffsetStore $offsets, int $reconnectAt): void
    {
        $lastPing = microtime(true);

        try {
            while (! $this->limitReached()) {
                if (now()->getTimestamp() >= $reconnectAt) {
                    $this->line('  Renewing the Fortnox token: reconnecting.');

                    return;
                }

                $event = $stream->next(min(5.0, max(0.5, $reconnectAt - now()->getTimestamp())));
                if ($event !== null) {
                    $this->handleEvent($event, $offsets);
                }

                if (microtime(true) - $lastPing >= self::PING_EVERY_SECONDS) {
                    $stream->ping();
                    $lastPing = microtime(true);
                }
            }
        } finally {
            $stream->close();
        }
    }

    private function handleEvent(FortnoxEvent $event, OffsetStore $offsets): void
    {
        // At-least-once delivery: the same offset can arrive twice.
        if (($offsets->all()[$event->topic] ?? null) === $event->offset) {
            return;
        }

        $this->line(sprintf('  %s  %-24s %s', now()->timezone(config('app.display_timezone'))->format('H:i:s'), $event->type, $event->entityId));
        Log::info('Fortnox event', ['topic' => $event->topic, 'type' => $event->type, 'entity' => $event->entityId, 'offset' => $event->offset]);

        try {
            FortnoxEventReceived::dispatch($event);
        } catch (Throwable $e) {
            // A failing reaction must not stop the stream; the event is logged and skipped.
            Log::error('Handling a Fortnox event failed', ['type' => $event->type, 'entity' => $event->entityId, 'error' => $e->getMessage()]);
            $this->components->error("Handling {$event->type} {$event->entityId} failed: {$e->getMessage()}");
        }

        $offsets->save($event->topic, $event->offset);
        ListenerStatus::event($event);
        $this->handled++;
    }

    /** @return list<string> */
    private function topics(): array
    {
        $topics = (string) ($this->option('topics') ?: config('erp.fortnox.events.topics'));

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $topics)))));
    }

    private function limitReached(): bool
    {
        $maxEvents = (int) $this->option('max-events');
        $maxSeconds = (int) $this->option('max-seconds');

        return ($maxEvents > 0 && $this->handled >= $maxEvents)
            || ($maxSeconds > 0 && microtime(true) - $this->startedAt >= $maxSeconds);
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // reported by the next attempt
        }
    }
}
