<?php

namespace App\Erp\Fortnox\Events;

/**
 * Fortnox's event stream protocol on top of a WebSocket connection:
 *
 *   → {"command":"add-tenants-v1","clientSecret":…,"accessTokens":["Bearer …"]}
 *   ← {"response":"add-tenants-v1","result":"ok","tenantIds":{"Bearer …":12345}}
 *   → {"command":"add-topics-v1","topics":[{"topic":"articles","offset":"…"}]}
 *   ← {"response":"add-topics-v1","result":"ok"}
 *   → {"command":"subscribe-v1"}
 *   ← {"response":"subscribe-v1","result":"ok"}
 *   ← {"topic":"articles","offset":"…","type":"article-updated-v1","tenantId":12345,"entityId":"A-1",…}
 *
 * See https://www.fortnox.se/developer/guides-and-good-to-know/websockets
 */
class EventStream
{
    public const URL = 'wss://ws.fortnox.se/topics-v1';

    private ?int $tenantId = null;

    public function __construct(private readonly Connection $connection) {}

    /**
     * Run the three commands. Returns the tenant id Fortnox gave the access token.
     *
     * @param  list<string>  $topics
     * @param  array<string, string>  $offsets  topic => last handled offset
     *
     * @throws EventStreamError when Fortnox refuses the token or a topic
     * @throws ConnectionClosed
     */
    public function start(string $clientSecret, string $accessToken, array $topics, array $offsets = [], float $timeout = 15): int
    {
        $bearer = 'Bearer '.$accessToken;

        $answer = $this->command(['command' => 'add-tenants-v1', 'clientSecret' => $clientSecret, 'accessTokens' => [$bearer]], $timeout);
        if (($answer['result'] ?? null) !== 'ok' || ! isset($answer['tenantIds'][$bearer])) {
            throw new EventStreamError('Fortnox refused the access token for the event stream. Log in to Fortnox again on the setup page.', invalidToken: true);
        }
        $this->tenantId = (int) $answer['tenantIds'][$bearer];

        $answer = $this->command([
            'command' => 'add-topics-v1',
            'topics' => array_map(fn (string $t) => isset($offsets[$t]) ? ['topic' => $t, 'offset' => $offsets[$t]] : ['topic' => $t], $topics),
        ], $timeout);
        if (($answer['result'] ?? null) !== 'ok') {
            $invalid = array_values(array_map('strval', $answer['invalidTopics'] ?? []));
            throw new EventStreamError(
                'Fortnox refused the topics: '.($invalid ? implode(', ', $invalid) : 'unknown reason')
                .'. The integration may lack the scope for them; log in to Fortnox again after adding it.',
                invalidTopics: $invalid,
            );
        }

        $answer = $this->command(['command' => 'subscribe-v1'], $timeout);
        if (($answer['result'] ?? null) !== 'ok') {
            throw new EventStreamError('Fortnox refused to start the subscription: '.json_encode($answer));
        }

        return $this->tenantId;
    }

    /**
     * The next event, or null if none arrived within $timeout seconds.
     * Events for other tenants (never expected: one tenant per installation) are skipped.
     *
     * @throws ConnectionClosed
     */
    public function next(float $timeout): ?FortnoxEvent
    {
        $deadline = microtime(true) + $timeout;

        do {
            $text = $this->connection->receive(max(0.0, $deadline - microtime(true)));
            if ($text === null) {
                return null;
            }

            $event = FortnoxEvent::fromMessage(json_decode($text, true) ?: []);
            if ($event !== null && ($this->tenantId === null || $event->tenantId === $this->tenantId)) {
                return $event;
            }
        } while (microtime(true) < $deadline);

        return null;
    }

    public function ping(): void
    {
        $this->connection->ping();
    }

    public function close(): void
    {
        $this->connection->close();
    }

    /** Send a command and wait for its response, skipping anything else that arrives first. */
    private function command(array $command, float $timeout): array
    {
        $this->connection->send(json_encode($command, JSON_UNESCAPED_SLASHES));
        $deadline = microtime(true) + $timeout;

        while (($left = $deadline - microtime(true)) > 0) {
            $text = $this->connection->receive($left);
            if ($text === null) {
                break;
            }
            $message = json_decode($text, true);
            if (is_array($message) && ($message['response'] ?? null) === $command['command']) {
                return $message;
            }
        }

        throw new ConnectionClosed("No answer from Fortnox to {$command['command']} within {$timeout} seconds");
    }
}
