<?php

namespace App\Models;

use Database\Factories\ProductSkuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'sku', 'woo_product_id', 'woo_variation_id', 'fortnox_article_number',
    'name', 'base_price', 'currency', 'is_active',
])]
class ProductSku extends Model
{
    /** @use HasFactory<ProductSkuFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasOne<InventoryCache, $this> */
    public function inventory(): HasOne
    {
        return $this->hasOne(InventoryCache::class);
    }

    /** @return MorphMany<SyncJob, $this> */
    public function syncJobs(): MorphMany
    {
        return $this->morphMany(SyncJob::class, 'syncable');
    }
}
