<?php

namespace App\Jobs;

use App\Models\OrderLog;
use App\Models\SyncJob;
use App\Services\Orders\OrderPricer;
use App\Services\Orders\UnpriceableOrderException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Takes one received WooCommerce order through the sync pipeline.
 *
 *   order_log:  received ──price──▶ priced ──ERP push (Day 11)──▶ synced
 *                   └───── unknown SKU etc. ─────▶ failed
 *
 * Each step is idempotent: a priced order is never re-priced, so running the job
 * twice (duplicate dispatch, retry) is harmless. Rows are locked while working.
 */
class SyncOrderToErp implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $syncJobId)
    {
        $this->onQueue('orders');
    }

    public function handle(OrderPricer $pricer): void
    {
        $syncJob = $this->claim();
        if ($syncJob === null) {
            return;
        }

        /** @var OrderLog $order */
        $order = $syncJob->syncable;

        try {
            if ($order->status === OrderLog::STATUS_RECEIVED) {
                $this->priceOrder($order, $pricer);
            }

            // Day 11: push $order to the ERP adapter here, then mark both rows synced/succeeded.
            $syncJob->update([
                'status' => SyncJob::STATUS_PENDING,
                'last_error' => null,
                'finished_at' => now(),
                'payload' => ['woo_order_id' => $order->woo_order_id, 'stage' => 'awaiting_erp'],
            ]);
        } catch (UnpriceableOrderException $e) {
            $order->update(['status' => OrderLog::STATUS_FAILED, 'last_error' => $e->getMessage()]);
            $syncJob->update(['status' => SyncJob::STATUS_FAILED, 'last_error' => $e->getMessage(), 'finished_at' => now()]);
            Log::warning('Order could not be priced', ['woo_order_id' => $order->woo_order_id, 'reason' => $e->getMessage()]);
        }
    }

    /** A crash outside our own error handling: record it so the row does not stay "running". */
    public function failed(Throwable $e): void
    {
        SyncJob::whereKey($this->syncJobId)->where('status', SyncJob::STATUS_RUNNING)->update([
            'status' => SyncJob::STATUS_FAILED,
            'last_error' => mb_strimwidth(class_basename($e).': '.$e->getMessage(), 0, 1000),
            'finished_at' => now(),
        ]);
    }

    /** Mark the sync job running, unless another worker has it or it is already done. */
    private function claim(): ?SyncJob
    {
        return DB::transaction(function () {
            $syncJob = SyncJob::whereKey($this->syncJobId)->lockForUpdate()->first();

            if ($syncJob === null
                || $syncJob->type !== SyncJob::TYPE_ORDER_PUSH
                || ! in_array($syncJob->status, [SyncJob::STATUS_PENDING, SyncJob::STATUS_FAILED], true)
                || ! $syncJob->syncable instanceof OrderLog) {
                return null;
            }

            $syncJob->update([
                'status' => SyncJob::STATUS_RUNNING,
                'attempts' => $syncJob->attempts + 1,
                'started_at' => now(),
                'finished_at' => null,
                'next_retry_at' => null,
            ]);

            return $syncJob;
        });
    }

    private function priceOrder(OrderLog $order, OrderPricer $pricer): void
    {
        DB::transaction(function () use ($order, $pricer) {
            $order = OrderLog::whereKey($order->id)->lockForUpdate()->first();
            if ($order->status !== OrderLog::STATUS_RECEIVED) {
                return; // priced by someone else in the meantime
            }

            $result = $pricer->price($order);

            $order->update([
                'status' => OrderLog::STATUS_PRICED,
                'priced_lines' => $result['lines'],
                'subtotal' => $result['subtotal'], // B2B subtotal ex. VAT; Woo's own figures stay in woo_payload
                'last_error' => null,
            ]);
        });

        $order->refresh();
    }
}
