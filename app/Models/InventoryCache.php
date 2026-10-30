<?php

namespace App\Models;

use Database\Factories\InventoryCacheFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * available_quantity is a generated column (warehouse - reserved - buffer, floored at 0):
 * read it, never write it.
 */
#[Table('inventory_cache')]
#[Fillable([
    'product_sku_id', 'warehouse_quantity', 'reserved_quantity', 'buffer_quantity',
    'erp_synced_at', 'woo_pushed_at',
])]
class InventoryCache extends Model
{
    /** @use HasFactory<InventoryCacheFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'warehouse_quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'buffer_quantity' => 'integer',
            'available_quantity' => 'integer',
            'erp_synced_at' => 'datetime',
            'woo_pushed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductSku, $this> */
    public function productSku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class);
    }
}
