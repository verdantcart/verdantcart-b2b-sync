<?php

namespace App\Models;

use Database\Factories\OrderLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Table('order_log')]
#[Fillable([
    'woo_order_id', 'b2b_customer_id', 'status', 'currency', 'subtotal', 'total',
    'woo_payload', 'priced_lines', 'erp_document_number', 'received_at', 'synced_at', 'last_error',
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
            'received_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
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
