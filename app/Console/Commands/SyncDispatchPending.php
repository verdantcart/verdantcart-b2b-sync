<?php

namespace App\Console\Commands;

use App\Models\OrderLog;
use App\Models\SyncJob;
use App\Services\Sync\SyncJobDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Re-queues sync work that is recorded in sync_jobs but not (or no longer) on the queue:
 * jobs whose dispatch failed (Redis down), jobs from before a deploy, or failed jobs after a fix.
 */
#[Signature('sync:dispatch-pending {--type= : order_push or inventory_sync (default: both)} {--include-failed : Also retry jobs marked failed} {--received-only : Orders: only those not yet priced}')]
#[Description('Queue every pending (or stuck) sync job again (safe: jobs are idempotent)')]
class SyncDispatchPending extends Command
{
    public function handle(): int
    {
        $statuses = [SyncJob::STATUS_PENDING, ...($this->option('include-failed') ? [SyncJob::STATUS_FAILED] : [])];

        $stuckBefore = now()->subMinutes(SyncJob::STUCK_AFTER_MINUTES);

        $jobs = SyncJob::query()
            ->where(fn ($q) => $q->whereIn('status', $statuses)
                // workers that died mid-job leave rows "running"; pick those up too
                ->orWhere(fn ($q) => $q->where('status', SyncJob::STATUS_RUNNING)
                    ->where(fn ($q) => $q->whereNull('started_at')->orWhere('started_at', '<', $stuckBefore))))
            ->when($this->option('type'), fn ($q, $type) => $q->where('type', $type))
            ->whereIn('type', [SyncJob::TYPE_ORDER_PUSH, SyncJob::TYPE_INVENTORY_SYNC])
            ->with('syncable')
            ->orderBy('id')
            ->get()
            ->when($this->option('received-only'), fn ($c) => $c->filter(fn (SyncJob $j) => $j->type !== SyncJob::TYPE_ORDER_PUSH
                || ($j->syncable instanceof OrderLog && $j->syncable->status === OrderLog::STATUS_RECEIVED)));

        foreach ($jobs as $job) {
            if ($job->status === SyncJob::STATUS_FAILED && $job->syncable instanceof OrderLog) {
                // give a previously failed order a fresh start
                $job->syncable->status === OrderLog::STATUS_FAILED && $job->syncable->update(['status' => OrderLog::STATUS_RECEIVED]);
            }

            app(SyncJobDispatcher::class)->dispatch($job);
        }

        $counts = $jobs->countBy('type');
        $this->components->info(sprintf('Queued %d order and %d inventory sync job(s).',
            $counts[SyncJob::TYPE_ORDER_PUSH] ?? 0, $counts[SyncJob::TYPE_INVENTORY_SYNC] ?? 0));

        return self::SUCCESS;
    }
}
