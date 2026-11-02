<?php

namespace App\Jobs;

use App\Models\SyncJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-buffers one SKU's stock and pushes the available quantity back to WooCommerce.
 *
 * Day 6 only queues this job. The work arrives on Day 10:
 *  - read warehouse stock from the ERP, update inventory_cache
 *  - push available_quantity to Woo only if it differs from what Woo reported (loop guard)
 * Until then the sync_jobs row stays "pending", which also keeps coalescing new signals into it.
 */
class SyncInventory implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $syncJobId)
    {
        $this->onQueue('inventory');
    }

    public function handle(): void
    {
        $syncJob = SyncJob::find($this->syncJobId);
        if ($syncJob === null || $syncJob->status !== SyncJob::STATUS_PENDING) {
            return;
        }

        // Implemented on Day 10.
    }
}
