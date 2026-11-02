<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

#[Signature('queue:heartbeat {--wait=10 : Seconds to wait for a worker to pick it up (0 = do not wait)}')]
#[Description('Dispatch a test job and report whether a queue worker processed it')]
class QueueHeartbeatCommand extends Command
{
    public function handle(): int
    {
        $before = Cache::get(QueueHeartbeat::CACHE_KEY);
        QueueHeartbeat::dispatch((string) microtime(true));
        $this->components->info('Heartbeat job dispatched to the "default" queue.');

        $this->table(['Queue', 'Waiting jobs'], collect(['orders', 'inventory', 'default'])
            ->map(function (string $q) {
                try {
                    return [$q, Queue::connection('redis')->size($q)];
                } catch (\Throwable) {
                    return [$q, 'Redis unavailable'];
                }
            }));

        $wait = (int) $this->option('wait');
        for ($i = 0; $i < $wait * 4; $i++) {
            $now = Cache::get(QueueHeartbeat::CACHE_KEY);
            if ($now !== null && $now !== $before) {
                $this->components->info("A worker processed it ({$now['latency_ms']} ms after dispatch).");

                return self::SUCCESS;
            }
            Sleep::usleep(250_000);
        }

        if ($wait > 0) {
            $this->components->warn("No worker picked it up within {$wait} s. Start one with: php artisan queue:work");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
