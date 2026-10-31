<?php

namespace App\Jobs;

use App\Models\SyncJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes one received WooCommerce order to the ERP.
 *
 * Day 5 only queues this job. The work itself arrives later in the sprint:
 *  - Day 9:  apply tier pricing, write priced_lines to order_log
 *  - Day 11: push to Fortnox through the ErpAdapter
 * Until then the job leaves the sync_jobs row "pending" so nothing is marked as synced by mistake.
 */
class SyncOrderToErp implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $syncJobId)
    {
        $this->onQueue('orders');
    }

    public function handle(): void
    {
        $syncJob = SyncJob::find($this->syncJobId);
        if ($syncJob === null || $syncJob->status === SyncJob::STATUS_SUCCEEDED) {
            return;
        }

        // Implemented on Day 9 / Day 11.
    }
}
