<?php

namespace App\Services\Inventory;

use App\Jobs\SyncInventory;
use App\Models\ProductSku;
use App\Models\SyncJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks for an inventory sync of one SKU. There is at most one *pending* inventory_sync job
 * per SKU: further requests are merged into it ("coalesced"). This is the loop guard for
 * product.updated webhooks, which our own stock push back to Woo also triggers.
 */
class InventorySyncRequester
{
    public const REDISPATCH_AFTER_MINUTES = 5;

    /** @return array{status: 'queued'|'coalesced', sync_job_id: int} */
    public function request(ProductSku $sku, array $payload = []): array
    {
        $wasPending = $sku->syncJobs()->where('type', SyncJob::TYPE_INVENTORY_SYNC)->where('status', SyncJob::STATUS_PENDING)->exists();

        [$syncJob, $dispatch] = DB::transaction(function () use ($sku, $payload) {
            $pending = $sku->syncJobs()
                ->where('type', SyncJob::TYPE_INVENTORY_SYNC)
                ->where('status', SyncJob::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if ($pending) {
                $previous = $pending->payload ?? [];
                $dispatchedAt = isset($previous['dispatched_at']) ? Carbon::parse($previous['dispatched_at']) : null;

                $pending->update(['payload' => array_merge($previous, $payload, [
                    'coalesced' => ($previous['coalesced'] ?? 0) + 1,
                ])]);

                // A pending row whose queue message was lost (worker restart, old code, Redis flush)
                // would otherwise wait forever. Re-dispatch it if it has not been queued recently;
                // running twice is harmless because the job claims the row under a lock.
                $stale = $dispatchedAt === null || $dispatchedAt->lt(now()->subMinutes(self::REDISPATCH_AFTER_MINUTES));

                return [$pending, $stale];
            }

            return [$sku->syncJobs()->create([
                'type' => SyncJob::TYPE_INVENTORY_SYNC,
                'status' => SyncJob::STATUS_PENDING,
                'payload' => $payload,
            ]), true];
        });

        if ($dispatch) {
            try {
                SyncInventory::dispatch($syncJob->id);
                $syncJob->update(['payload' => array_merge($syncJob->payload ?? [], ['dispatched_at' => now()->toIso8601String()])]);
            } catch (Throwable $e) {
                $syncJob->update(['last_error' => 'Queue dispatch failed: '.$e->getMessage(), 'next_retry_at' => now()]);
                Log::error('Could not queue inventory sync; left as pending', [
                    'sku' => $sku->sku, 'sync_job_id' => $syncJob->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return ['status' => $wasPending ? 'coalesced' : 'queued', 'sync_job_id' => $syncJob->id];
    }
}
