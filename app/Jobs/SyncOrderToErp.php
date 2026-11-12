<?php

namespace App\Jobs;

use App\Erp\ErpAdapter;
use App\Erp\ErpException;
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
 *   order_log:  received ──price──▶ priced ──ERP push──▶ synced
 *                   └── unknown SKU ──▶ failed     └── ERP error: stays priced, sync job failed
 *
 * Retries never create a second ERP order: see pushOnce().
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

    public function handle(OrderPricer $pricer, ErpAdapter $erp): void
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

            if ($order->erp_document_number === null) {
                $documentNumber = $this->pushOnce($syncJob, $order, $erp);
                $order->update([
                    'status' => OrderLog::STATUS_SYNCED,
                    'erp_document_number' => $documentNumber,
                    'synced_at' => now(),
                    'last_error' => null,
                ]);
            }

            $syncJob->update([
                'status' => SyncJob::STATUS_SUCCEEDED,
                'last_error' => null,
                'next_retry_at' => null,
                'finished_at' => now(),
                'payload' => ['woo_order_id' => $order->woo_order_id, 'erp' => $erp->name(), 'erp_document_number' => $order->erp_document_number],
            ]);
        } catch (ErpException $e) {
            // The order stays "priced" (still a valid sale, keeps reserving stock); only the ERP
            // step failed. Retryable errors are retried with backoff by `sync:retry-due`.
            $order->update(['last_error' => $e->getMessage()]);
            $syncJob->recordFailure($e->getMessage(), $e->retryable, ['woo_order_id' => $order->woo_order_id, 'erp' => $erp->name(), 'stage' => 'erp_push']);
            Log::warning('Order could not be pushed to the ERP', [
                'woo_order_id' => $order->woo_order_id, 'erp' => $erp->name(), 'reason' => $e->getMessage(),
                'attempt' => $syncJob->attempts, 'status' => $syncJob->status,
            ]);
        } catch (UnpriceableOrderException $e) {
            $order->update(['status' => OrderLog::STATUS_FAILED, 'last_error' => $e->getMessage()]);
            $syncJob->update(['status' => SyncJob::STATUS_FAILED, 'last_error' => $e->getMessage(), 'finished_at' => now()]);
            Log::warning('Order could not be priced', ['woo_order_id' => $order->woo_order_id, 'reason' => $e->getMessage()]);
        }
    }

    /**
     * Push the order, but never twice: a marker is saved before the ERP call, and if a previous
     * attempt got that far (crash, timeout after the ERP accepted it) we look the order up first.
     */
    private function pushOnce(SyncJob $syncJob, OrderLog $order, ErpAdapter $erp): string
    {
        if (isset($syncJob->payload['erp_push_started_at']) && $existing = $erp->findOrder($order)) {
            Log::info('Order already existed in the ERP; linked instead of creating it again', [
                'woo_order_id' => $order->woo_order_id, 'erp_document_number' => $existing,
            ]);

            return $existing;
        }

        $syncJob->update(['payload' => array_merge($syncJob->payload ?? [], ['erp_push_started_at' => now()->toIso8601String()])]);

        return $erp->pushOrder($order);
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
                || ! $syncJob->isClaimable()
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
                'price_difference' => $result['difference'],
                'last_error' => null,
            ]);

            // Fortnox still gets the B2B price (the agreed price); a person decides about the difference.
            if ($result['difference'] !== '0.00') {
                Log::warning('Shop charged a different price than the B2B price', [
                    'woo_order_id' => $order->woo_order_id,
                    'difference' => $result['difference'],
                    'lines' => collect($result['lines'])
                        ->filter(fn (array $line) => $line['difference'] !== '0.00')
                        ->map(fn (array $line) => "{$line['sku']}: shop {$line['woo_line_total']}, B2B {$line['line_total']}")
                        ->values()->all(),
                ]);
            }
        });

        $order->refresh();
    }
}
