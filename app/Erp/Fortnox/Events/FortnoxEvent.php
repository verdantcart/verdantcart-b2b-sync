<?php

namespace App\Erp\Fortnox\Events;

use Illuminate\Support\Carbon;

/**
 * One change in Fortnox, as the event stream reports it. Events are minimal: they say
 * what changed (topic, type, entity id), and the API is asked for the details.
 */
final class FortnoxEvent
{
    /** @param array<string, mixed> $extra event-specific fields, e.g. fullyPaid on payments */
    public function __construct(
        public readonly string $topic,
        public readonly string $offset,
        public readonly string $type,
        public readonly int $tenantId,
        public readonly string $entityId,
        public readonly ?Carbon $timestamp,
        public readonly array $extra = [],
    ) {}

    /** Null when the message isn't an event (a command response, or something unknown). */
    public static function fromMessage(array $message): ?self
    {
        foreach (['topic', 'offset', 'type', 'tenantId'] as $field) {
            if (! isset($message[$field])) {
                return null;
            }
        }

        $known = ['topic', 'offset', 'type', 'tenantId', 'entityId', 'timestamp'];

        return new self(
            topic: (string) $message['topic'],
            offset: (string) $message['offset'],
            type: (string) $message['type'],
            tenantId: (int) $message['tenantId'],
            entityId: (string) ($message['entityId'] ?? ''),
            timestamp: isset($message['timestamp']) ? Carbon::parse($message['timestamp']) : null,
            extra: array_diff_key($message, array_flip($known)),
        );
    }

    /** "article-updated-v1" → "updated" */
    public function action(): string
    {
        return preg_match('/-(created|updated|deleted|cancelled|canceled|changed|bookkeep)-v\d+$/', $this->type, $m) ? $m[1] : $this->type;
    }
}
