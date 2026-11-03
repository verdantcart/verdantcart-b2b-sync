<?php

namespace App\Jobs;

use App\Models\InventoryCache;
use App\Models\ProductSku;
use App\Models\SyncJob;
use App\Services\Inventory\ReservationCalculator;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCommerceException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-buffers one SKU and pushes the sellable quantity to WooCommerce.
 *
 *   available = max(warehouse − reserved − buffer, 0)
 *
 * - warehouse: owned by the ERP (Day 11 refreshes it here); seeded from Woo the first time
 *   a SKU appears, and settable with `php artisan inventory:set` until then.
 * - reserved:  quantities on orders received/priced but not yet in the ERP.
 * - buffer:    per-SKU safety margin.
 *
 * Loop guard: we only write to Woo when its stock differs. Our write fires product.updated,
 * the next run finds Woo already correct, and stops.
 */
class SyncInventory implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $syncJobId)
    {
        $this->onQueue('inventory');
    }

    public function handle(ReservationCalculator $reservations): void
    {
        $syncJob = $this->claim();
        if ($syncJob === null) {
            return;
        }

        /** @var ProductSku $sku */
        $sku = $syncJob->syncable;

        $inventory = $this->rebuffer($sku, $syncJob->payload ?? [], $reservations);
        $result = [
            'warehouse' => $inventory->warehouse_quantity,
            'reserved' => $inventory->reserved_quantity,
            'buffer' => $inventory->buffer_quantity,
            'available' => $inventory->available_quantity,
        ];

        if ($sku->woo_product_id === null) {
            $this->finish($syncJob, SyncJob::STATUS_SUCCEEDED, $result + ['pushed' => false, 'reason' => 'SKU not linked to a WooCommerce product']);

            return;
        }

        try {
            $result += $this->pushToWoo(app(WooCommerceClient::class), $sku, $inventory);
        } catch (WooCommerceException $e) {
            $this->finish($syncJob, SyncJob::STATUS_FAILED, $result + ['pushed' => false], $e->getMessage(), $e->isRetryable());

            return;
        }

        $this->finish($syncJob, SyncJob::STATUS_SUCCEEDED, $result);
    }

    public function failed(Throwable $e): void
    {
        SyncJob::whereKey($this->syncJobId)->where('status', SyncJob::STATUS_RUNNING)->update([
            'status' => SyncJob::STATUS_FAILED,
            'last_error' => mb_strimwidth(class_basename($e).': '.$e->getMessage(), 0, 1000),
            'finished_at' => now(),
        ]);
    }

    private function claim(): ?SyncJob
    {
        return DB::transaction(function () {
            $syncJob = SyncJob::whereKey($this->syncJobId)->lockForUpdate()->first();

            if ($syncJob === null
                || $syncJob->type !== SyncJob::TYPE_INVENTORY_SYNC
                || ! $syncJob->isClaimable()
                || ! $syncJob->syncable instanceof ProductSku) {
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

    private function rebuffer(ProductSku $sku, array $payload, ReservationCalculator $reservations): InventoryCache
    {
        return DB::transaction(function () use ($sku, $payload, $reservations) {
            $inventory = InventoryCache::where('product_sku_id', $sku->id)->lockForUpdate()->first()
                ?? InventoryCache::create([
                    'product_sku_id' => $sku->id,
                    // First sighting of this SKU: until the ERP reports, trust Woo's own figure.
                    'warehouse_quantity' => max((int) ($payload['woo_stock_quantity'] ?? 0), 0),
                    'buffer_quantity' => (int) config('b2bsync.inventory.default_buffer', 0),
                ]);

            $inventory->update(['reserved_quantity' => $reservations->reservedFor($sku)]);

            return $inventory->refresh(); // reload the generated available_quantity
        });
    }

    /** @return array{woo_stock_before: ?int, pushed: bool} */
    private function pushToWoo(WooCommerceClient $woo, ProductSku $sku, InventoryCache $inventory): array
    {
        $endpoint = $sku->woo_variation_id
            ? "products/{$sku->woo_product_id}/variations/{$sku->woo_variation_id}"
            : "products/{$sku->woo_product_id}";

        $current = $woo->get($endpoint);
        $wooStock = ($current['manage_stock'] ?? false) === true && isset($current['stock_quantity'])
            ? (int) $current['stock_quantity']
            : null;

        if ($wooStock === $inventory->available_quantity) {
            return ['woo_stock_before' => $wooStock, 'pushed' => false];
        }

        $woo->put($endpoint, ['manage_stock' => true, 'stock_quantity' => $inventory->available_quantity]);
        $inventory->update(['woo_pushed_at' => now()]);

        return ['woo_stock_before' => $wooStock, 'pushed' => true];
    }

    private function finish(SyncJob $syncJob, string $status, array $result, ?string $error = null, bool $retryable = false): void
    {
        $syncJob->update([
            'status' => $status,
            'payload' => array_merge($syncJob->payload ?? [], ['result' => $result]),
            'last_error' => $error,
            'next_retry_at' => $retryable ? now()->addMinute() : null,
            'finished_at' => now(),
        ]);
    }
}
