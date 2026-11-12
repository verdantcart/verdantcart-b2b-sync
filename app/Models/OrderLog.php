<?php

namespace App\Models;

use Database\Factories\OrderLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Table('order_log')]
#[Fillable([
    'woo_order_id', 'b2b_customer_id', 'status', 'currency', 'subtotal', 'total',
    'woo_payload', 'priced_lines', 'price_difference', 'erp_document_number', 'received_at', 'synced_at', 'last_error',
])]
class OrderLog extends Model
{
    /** @use HasFactory<OrderLogFactory> */
    use HasFactory;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PRICED = 'priced';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'woo_payload' => 'array',
            'priced_lines' => 'array',
            'price_difference' => 'decimal:2',
            'received_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Orders where the shop charged noticeably more or less than the B2B price.
     * Differences up to the tolerance are rounding (WooCommerce keeps prices like 54.1666).
     */
    public function scopeWithPriceDifference(Builder $query): void
    {
        $tolerance = self::priceTolerance();
        $query->whereNotNull('price_difference')
            ->where(fn (Builder $q) => $q->where('price_difference', '>', $tolerance)->orWhere('price_difference', '<', -$tolerance));
    }

    public function hasPriceDifference(): bool
    {
        return $this->price_difference !== null && abs((float) $this->price_difference) > self::priceTolerance();
    }

    public static function priceTolerance(): float
    {
        return max(0.0, (float) config('b2bsync.price_check.tolerance', 1.00));
    }

    /** @return BelongsTo<B2bCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(B2bCustomer::class, 'b2b_customer_id');
    }

    /** @return MorphMany<SyncJob, $this> */
    public function syncJobs(): MorphMany
    {
        return $this->morphMany(SyncJob::class, 'syncable');
    }
}
