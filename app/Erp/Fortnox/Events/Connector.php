<?php

namespace App\Erp\Fortnox\Events;

/** Opens connections, so tests can hand the listener a fake one. */
class Connector
{
    /** @throws ConnectionClosed when the connection can't be opened */
    public function connect(string $url, float $timeout = 10): Connection
    {
        return WebSocketClient::open($url, $timeout);
    }
}
