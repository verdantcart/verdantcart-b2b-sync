<?php

namespace Database\Factories;

use App\Models\OrderLog;
use App\Models\SyncJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncJob>
 */
class SyncJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => SyncJob::TYPE_ORDER_PUSH,
            'status' => SyncJob::STATUS_SUCCEEDED,
            'syncable_type' => 'order_log',
            'syncable_id' => OrderLog::factory(),
            'attempts' => 1,
            'payload' => null,
            'started_at' => $start = fake()->dateTimeBetween('-14 days'),
            'finished_at' => (clone $start)->modify('+2 seconds'),
        ];
    }

    public function failed(int $attempts = 3): static
    {
        return $this->state(fn () => [
            'status' => SyncJob::STATUS_FAILED,
            'attempts' => $attempts,
            'last_error' => 'Fortnox 429: Too Many Requests',
            'next_retry_at' => now()->addMinutes(2 ** $attempts),
        ]);
    }
}
