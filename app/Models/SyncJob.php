<?php

namespace App\Models;

use App\Services\Alerts\Alerter;
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

    /** After this many attempts a retryable failure becomes "dead" and needs a person. */
    public const MAX_ATTEMPTS = 6;

    /** Minutes to wait before attempt n+1: 1, 2, 4, 8, 16 … capped at 60. */
    public static function retryDelayMinutes(int $attempts): int
    {
        return min(2 ** max($attempts - 1, 0), 60);
    }

    /**
     * Record a failure. Retryable errors get a retry time with exponential backoff until
     * MAX_ATTEMPTS, then the job is "dead". Non-retryable errors wait for a manual retry.
     */
    public function recordFailure(string $error, bool $retryable, array $payload = []): void
    {
        $exhausted = $this->attempts >= self::MAX_ATTEMPTS;

        $this->update([
            'status' => $retryable && $exhausted ? self::STATUS_DEAD : self::STATUS_FAILED,
            'last_error' => mb_strimwidth($error, 0, 1000, '…'),
            'next_retry_at' => $retryable && ! $exhausted ? now()->addMinutes(self::retryDelayMinutes($this->attempts)) : null,
            'finished_at' => now(),
            'payload' => array_merge($this->payload ?? [], $payload),
        ]);

        if ($this->status === self::STATUS_DEAD || ! $retryable) {
            app(Alerter::class)->syncGaveUp($this);
        }
    }
}
