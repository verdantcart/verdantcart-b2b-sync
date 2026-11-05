<?php

namespace App\Console\Commands;

use App\Models\SyncJob;
use App\Services\Sync\SyncJobDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every minute from the scheduler (routes/console.php). Re-queues failed jobs whose
 * backoff has passed and jobs left "running" by a worker that died.
 */
#[Signature('sync:retry-due')]
#[Description('Retry failed sync jobs whose retry time has come (exponential backoff)')]
class SyncRetryDue extends Command
{
    public function handle(SyncJobDispatcher $dispatcher): int
    {
        $due = SyncJob::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', SyncJob::STATUS_FAILED)->whereNotNull('next_retry_at')->where('next_retry_at', '<=', now()))
                ->orWhere(fn ($q) => $q->where('status', SyncJob::STATUS_RUNNING)->where('started_at', '<', now()->subMinutes(SyncJob::STUCK_AFTER_MINUTES))))
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($due as $job) {
            $job->update(['next_retry_at' => null]); // not picked up again by the next run
            $dispatcher->dispatch($job);
        }

        $this->components->info("Re-queued {$due->count()} sync job(s).");

        return self::SUCCESS;
    }
}
