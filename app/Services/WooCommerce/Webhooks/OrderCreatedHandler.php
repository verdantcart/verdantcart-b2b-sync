<?php

namespace App\Services\WooCommerce\Webhooks;

use App\Jobs\SyncOrderToErp;
use App\Models\B2bCustomer;
use App\Models\OrderLog;
use App\Models\SyncJob;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * order.created: record the order and queue it for ERP sync.
 *
 * - Idempotent: WooCommerce may deliver the same order more than once; we key on woo_order_id.
 * - Outbox: the order and its sync_jobs row are committed first, then the queue job is dispatched.
 *   If dispatching fails (e.g. Redis is down) the sync_jobs row stays "pending" and can be
 *   re-dispatched later, and WooCommerce still gets a 2xx so it does not disable the webhook.
 */
class OrderCreatedHandler
{
    /** @return array{status: string, order_log_id: int, sync_job_id: int|null} */
    public function handle(array $order): array
    {
        try {
            return $this->record($order);
        } catch (UniqueConstraintViolationException) {
            // Two deliveries of the same order raced; the other one won, so this one is a duplicate.
            return $this->record($order);
        }
    }

    private function record(array $order): array
    {
        [$orderLog, $syncJob, $isNew] = DB::transaction(function () use ($order) {
            $orderLog = OrderLog::where('woo_order_id', $order['id'])->lockForUpdate()->first();
            $isNew = $orderLog === null;

            $orderLog ??= OrderLog::create([
                'woo_order_id' => $order['id'],
                'b2b_customer_id' => $this->matchCustomer($order)?->id,
                'status' => OrderLog::STATUS_RECEIVED,
                'currency' => $order['currency'] ?? 'SEK',
                'subtotal' => $this->subtotal($order),
                'total' => $order['total'] ?? null,
                'woo_payload' => $order,
                'received_at' => isset($order['date_created_gmt'])
                    ? Carbon::parse($order['date_created_gmt'], 'UTC')
                    : now(),
            ]);

            $syncJob = $orderLog->syncJobs()->where('type', SyncJob::TYPE_ORDER_PUSH)->first()
                ?? $orderLog->syncJobs()->create([
                    'type' => SyncJob::TYPE_ORDER_PUSH,
                    'status' => SyncJob::STATUS_PENDING,
                    'payload' => ['woo_order_id' => $order['id']],
                ]);

            return [$orderLog, $syncJob, $isNew];
        });

        if (! $isNew) {
            return ['status' => 'duplicate', 'order_log_id' => $orderLog->id, 'sync_job_id' => $syncJob->id];
        }

        try {
            SyncOrderToErp::dispatch($syncJob->id);
        } catch (Throwable $e) {
            $syncJob->update(['last_error' => 'Queue dispatch failed: '.$e->getMessage(), 'next_retry_at' => now()]);
            Log::error('Could not queue order sync; left as pending', [
                'woo_order_id' => $order['id'], 'sync_job_id' => $syncJob->id, 'error' => $e->getMessage(),
            ]);
        }

        return ['status' => 'queued', 'order_log_id' => $orderLog->id, 'sync_job_id' => $syncJob->id];
    }

    /** Match on the Woo customer ID first, then on billing e-mail (guest checkouts). */
    private function matchCustomer(array $order): ?B2bCustomer
    {
        $wooCustomerId = (int) ($order['customer_id'] ?? 0);
        if ($wooCustomerId > 0 && $customer = B2bCustomer::where('woo_customer_id', $wooCustomerId)->first()) {
            return $customer;
        }

        $email = strtolower(trim($order['billing']['email'] ?? ''));

        return $email === '' ? null : B2bCustomer::whereRaw('lower(email) = ?', [$email])->first();
    }

    private function subtotal(array $order): ?string
    {
        $lines = $order['line_items'] ?? [];

        return $lines === [] ? null : number_format(
            array_sum(array_map(fn ($line) => (float) ($line['subtotal'] ?? 0), $lines)), 2, '.', ''
        );
    }
}
