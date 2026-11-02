<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tiny job that proves a worker is consuming the queue: it records when it was
 * dispatched and when it ran, which the dashboard shows as "queue worker: last seen".
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY = 'queue:heartbeat';

    public function __construct(public string $dispatchedAt)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $latencyMs = (int) round((microtime(true) - (float) $this->dispatchedAt) * 1000);

        Cache::put(self::CACHE_KEY, ['ran_at' => now()->toIso8601String(), 'latency_ms' => $latencyMs], now()->addDay());
        Log::info('Queue heartbeat processed', ['latency_ms' => $latencyMs]);
    }
}
