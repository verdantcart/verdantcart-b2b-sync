<?php

namespace Database\Factories;

use App\Models\InventoryCache;
use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryCache>
 */
class InventoryCacheFactory extends Factory
{
    public function definition(): array
    {
        $warehouse = fake()->numberBetween(0, 800);

        return [
            'product_sku_id' => ProductSku::factory(),
            'warehouse_quantity' => $warehouse,
            'reserved_quantity' => fake()->numberBetween(0, (int) ($warehouse * 0.3)),
            'buffer_quantity' => fake()->randomElement([0, 5, 10, 20]),
            'erp_synced_at' => fake()->dateTimeBetween('-2 hours'),
            'woo_pushed_at' => fake()->dateTimeBetween('-2 hours'),
        ];
    }

    /** Stock at or below the safety buffer: shows as 0 available in Woo. */
    public function lowStock(): static
    {
        return $this->state(fn () => [
            'warehouse_quantity' => $q = fake()->numberBetween(0, 10),
            'reserved_quantity' => 0,
            'buffer_quantity' => max($q, 5),
        ]);
    }
}
