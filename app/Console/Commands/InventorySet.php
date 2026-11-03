<?php

namespace App\Console\Commands;

use App\Models\InventoryCache;
use App\Models\ProductSku;
use App\Services\Inventory\InventorySyncRequester;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sets the warehouse figure (and optionally the safety buffer) for a SKU, then queues a sync.
 * Stands in for the ERP until the Fortnox adapter supplies warehouse stock (Day 11),
 * and stays useful for manual corrections.
 */
#[Signature('inventory:set {sku} {warehouse : Units in the warehouse} {--buffer= : Safety buffer for this SKU}')]
#[Description('Set warehouse stock for a SKU and push the buffered quantity to WooCommerce')]
class InventorySet extends Command
{
    public function handle(InventorySyncRequester $requester): int
    {
        $sku = ProductSku::where('sku', $this->argument('sku'))->first();
        if ($sku === null) {
            $this->components->error("Unknown SKU [{$this->argument('sku')}].");

            return self::FAILURE;
        }

        $warehouse = (int) $this->argument('warehouse');
        $buffer = $this->option('buffer');
        if ($warehouse < 0 || ($buffer !== null && (int) $buffer < 0)) {
            $this->components->error('Quantities cannot be negative.');

            return self::FAILURE;
        }

        $inventory = InventoryCache::firstOrNew(['product_sku_id' => $sku->id]);
        $inventory->warehouse_quantity = $warehouse;
        $inventory->buffer_quantity = $buffer !== null ? (int) $buffer : ($inventory->buffer_quantity ?? (int) config('b2bsync.inventory.default_buffer'));
        $inventory->erp_synced_at = now();
        $inventory->save();

        $requester->request($sku, ['source' => 'inventory:set']);

        $this->components->info("{$sku->sku}: warehouse {$warehouse}, buffer {$inventory->buffer_quantity}. Sync queued; the worker will push the result to WooCommerce.");

        return self::SUCCESS;
    }
}
