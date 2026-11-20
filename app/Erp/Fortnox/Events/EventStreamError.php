<?php

namespace App\Erp\Fortnox\Events;

use RuntimeException;

/**
 * Fortnox refused a command: an invalid or expired token, or topics this integration may
 * not subscribe to. Reconnecting with the same input won't help, so it is reported as is.
 */
class EventStreamError extends RuntimeException
{
    /** @param list<string> $invalidTopics */
    public function __construct(string $message, public readonly array $invalidTopics = [], public readonly bool $invalidToken = false)
    {
        parent::__construct($message);
    }
}
