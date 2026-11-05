<?php

namespace App\Services\Sync;

use App\Jobs\SyncInventory;
use App\Jobs\SyncOrderToErp;
use App\Models\OrderLog;
use App\Models\SyncJob;
use InvalidArgumentException;

/** Puts a sync_jobs row (back) on the right queue. */
class SyncJobDispatcher
{
    public function dispatch(SyncJob $job): void
    {
        match ($job->type) {
            SyncJob::TYPE_ORDER_PUSH => SyncOrderToErp::dispatch($job->id),
            SyncJob::TYPE_INVENTORY_SYNC => SyncInventory::dispatch($job->id),
            default => throw new InvalidArgumentException("No queue job for sync type [{$job->type}]"),
        };
    }

    /**
     * Manual retry (from the failed-jobs page): a person has looked at it, so dead jobs get
     * a fresh set of attempts and a failed order is allowed to be priced again.
     */
    public function retryNow(SyncJob $job): void
    {
        if (! in_array($job->status, [SyncJob::STATUS_FAILED, SyncJob::STATUS_DEAD], true)) {
            throw new InvalidArgumentException('Only failed or dead jobs can be retried.');
        }

        if ($job->syncable instanceof OrderLog && $job->syncable->status === OrderLog::STATUS_FAILED) {
            $job->syncable->update(['status' => OrderLog::STATUS_RECEIVED]);
        }

        $job->update([
            'status' => SyncJob::STATUS_FAILED, // claimable
            'attempts' => $job->status === SyncJob::STATUS_DEAD ? 0 : $job->attempts,
            'next_retry_at' => null,
        ]);

        $this->dispatch($job);
    }
}
