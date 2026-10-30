<?php

namespace App\Models;

use Database\Factories\SyncJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'type', 'status', 'syncable_type', 'syncable_id', 'attempts', 'payload',
    'last_error', 'next_retry_at', 'started_at', 'finished_at',
])]
class SyncJob extends Model
{
    /** @use HasFactory<SyncJobFactory> */
    use HasFactory;

    public const TYPE_ORDER_PUSH = 'order_push';
    public const TYPE_INVENTORY_SYNC = 'inventory_sync';
    public const TYPE_CUSTOMER_PULL = 'customer_pull';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DEAD = 'dead';

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'payload' => 'array',
            'next_retry_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function syncable(): MorphTo
    {
        return $this->morphTo();
    }
}
