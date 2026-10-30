<?php

namespace Database\Factories;

use App\Models\B2bCustomer;
use App\Models\OrderLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLog>
 */
class OrderLogFactory extends Factory
{
    public function definition(): array
    {
        $orderId = fake()->unique()->numberBetween(10000, 99999);
        $subtotal = fake()->randomFloat(2, 500, 40000);
        $receivedAt = fake()->dateTimeBetween('-14 days');

        return [
            'woo_order_id' => $orderId,
            'b2b_customer_id' => B2bCustomer::factory(),
            'status' => OrderLog::STATUS_RECEIVED,
            'currency' => 'SEK',
            'subtotal' => $subtotal,
            'total' => round($subtotal * 1.25, 2), // incl. 25 % moms
            'woo_payload' => ['id' => $orderId, 'status' => 'processing', 'currency' => 'SEK'],
            'priced_lines' => null,
            'received_at' => $receivedAt,
        ];
    }

    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderLog::STATUS_SYNCED,
            'priced_lines' => [],
            'erp_document_number' => (string) fake()->unique()->numberBetween(1, 99999),
            'synced_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => OrderLog::STATUS_FAILED,
            'last_error' => fake()->randomElement([
                'Fortnox 429: Too Many Requests',
                'Fortnox 400: Customer number does not exist',
                'Fortnox 503: Service unavailable',
            ]),
        ]);
    }
}
