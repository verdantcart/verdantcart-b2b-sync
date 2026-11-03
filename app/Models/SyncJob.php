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

    /** A worker that dies mid-job (crash, timeout, reboot) leaves the row "running"; after this it may be taken over. */
    public const STUCK_AFTER_MINUTES = 10;

    public function isStuck(): bool
    {
        return $this->status === self::STATUS_RUNNING
            && ($this->started_at === null || $this->started_at->lt(now()->subMinutes(self::STUCK_AFTER_MINUTES)));
    }

    public function isClaimable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED], true) || $this->isStuck();
    }
}
