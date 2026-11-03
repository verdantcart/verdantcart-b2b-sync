<?php

namespace App\Services\WooCommerce\Webhooks;

use App\Models\ProductSku;
use App\Services\Inventory\InventorySyncRequester;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * product.created / product.updated: keep the SKU catalogue in step with WooCommerce and
 * queue an inventory sync for that SKU.
 *
 * Stock itself is owned by the ERP (inventory_cache.warehouse_quantity), so the stock number
 * Woo reports is only recorded on the sync job, never written to the buffer here.
 *
 * Loop guard: from Day 10 the app pushes stock back to Woo, which fires product.updated again.
 * There is therefore at most one pending inventory_sync job per SKU; further signals are merged
 * into it ("coalesced") instead of creating new work.
 */
class ProductUpdatedHandler
{
    public function __construct(private readonly InventorySyncRequester $inventory) {}

    /** @return array{status: string, product_sku_id?: int, sync_job_id?: int, reason?: string} */
    public function handle(array $product): array
    {
        $sku = trim((string) ($product['sku'] ?? ''));

        if (($product['type'] ?? null) === 'variable') {
            return ['status' => 'ignored', 'reason' => 'variable parent; stock is tracked on its variations'];
        }
        if ($sku === '') {
            return ['status' => 'ignored', 'reason' => 'product has no SKU'];
        }

        try {
            return $this->record($product, $sku);
        } catch (UniqueConstraintViolationException) {
            // Two deliveries for a brand-new SKU raced; the other created it, so retry once.
            return $this->record($product, $sku);
        }
    }

    private function record(array $product, string $sku): array
    {
        $productSku = DB::transaction(function () use ($product, $sku) {
            $productSku = $this->findSku($product, $sku);
            $attributes = $this->attributesFrom($product, $sku);

            $productSku === null
                ? $productSku = ProductSku::create($attributes)
                : $productSku->update($attributes);

            return $productSku;
        });

        $result = $this->inventory->request($productSku, [
            'source' => 'webhook',
            'woo_product_id' => (int) $product['id'],
            'woo_stock_quantity' => isset($product['stock_quantity']) ? (int) $product['stock_quantity'] : null,
            'woo_stock_status' => $product['stock_status'] ?? null,
            'woo_modified_gmt' => $product['date_modified_gmt'] ?? null,
        ]);

        return $result + ['product_sku_id' => $productSku->id];
    }

    /** SKU first; fall back to the Woo IDs so a SKU renamed in Woo keeps its history. */
    private function findSku(array $product, string $sku): ?ProductSku
    {
        $found = ProductSku::where('sku', $sku)->lockForUpdate()->first();
        if ($found) {
            return $found;
        }

        [$productId, $variationId] = $this->wooIds($product);

        return ProductSku::where('woo_product_id', $productId)
            ->where(fn ($q) => $variationId ? $q->where('woo_variation_id', $variationId) : $q->whereNull('woo_variation_id'))
            ->lockForUpdate()
            ->first();
    }

    private function attributesFrom(array $product, string $sku): array
    {
        [$productId, $variationId] = $this->wooIds($product);
        $price = $product['regular_price'] ?? '';

        return array_filter([
            'sku' => $sku,
            'woo_product_id' => $productId,
            'woo_variation_id' => $variationId,
            'name' => $product['name'] ?? null,
            'base_price' => is_numeric($price) ? $price : null, // keep the old price if Woo sends none
            'is_active' => ($product['status'] ?? 'publish') === 'publish',
        ], fn ($v) => $v !== null) + ['woo_variation_id' => $variationId];
    }

    /** A variation's own id is the variation id; its parent is the product. */
    private function wooIds(array $product): array
    {
        $isVariation = ($product['type'] ?? null) === 'variation' || (int) ($product['parent_id'] ?? 0) > 0;

        return $isVariation
            ? [(int) $product['parent_id'], (int) $product['id']]
            : [(int) $product['id'], null];
    }
}
