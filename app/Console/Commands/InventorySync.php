<?php

namespace App\Console\Commands;

use App\Models\ProductSku;
use App\Services\Inventory\InventorySyncRequester;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('inventory:sync {sku?* : One or more SKUs} {--all : Every active SKU linked to WooCommerce}')]
#[Description('Queue an inventory sync (re-buffer + push to WooCommerce) for SKUs')]
class InventorySync extends Command
{
    public function handle(InventorySyncRequester $requester): int
    {
        $skus = $this->option('all')
            ? ProductSku::where('is_active', true)->whereNotNull('woo_product_id')->orderBy('sku')->get()
            : ProductSku::whereIn('sku', $this->argument('sku'))->orderBy('sku')->get();

        $unknown = array_diff($this->argument('sku'), $skus->pluck('sku')->all());
        if ($unknown) {
            $this->components->error('Unknown SKU: '.implode(', ', $unknown));

            return self::FAILURE;
        }
        if ($skus->isEmpty()) {
            $this->components->warn('Nothing to sync. Pass SKUs or use --all.');

            return self::FAILURE;
        }

        $statuses = $skus->map(fn (ProductSku $sku) => $requester->request($sku, ['source' => 'manual'])['status']);
        $this->components->info(sprintf('%d SKU(s): %d queued, %d merged into a waiting sync.',
            $skus->count(), $statuses->filter(fn ($s) => $s === 'queued')->count(), $statuses->filter(fn ($s) => $s === 'coalesced')->count()));

        return self::SUCCESS;
    }
}
