<?php

namespace App\Erp\Fortnox\Events;

/**
 * One open WebSocket connection that exchanges text messages.
 * WebSocketClient is the real one; tests use a fake.
 */
interface Connection
{
    public function send(string $text): void;

    /**
     * The next text message, or null if none arrived within $timeout seconds.
     *
     * @throws ConnectionClosed when the server or the network ends the connection
     */
    public function receive(float $timeout): ?string;

    /** Keep-alive: some proxies drop connections that are silent for a minute. */
    public function ping(): void;

    public function close(): void;
}
