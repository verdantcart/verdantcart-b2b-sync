<?php

namespace Database\Seeders;

use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use App\Models\InventoryCache;
use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\SyncJob;
use Illuminate\Database\Seeder;

/**
 * Synthetic data for local development and the public demo.
 * Re-run with: php artisan migrate:fresh --seed
 */
class B2bDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = collect([
            ['name' => 'Återförsäljare', 'slug' => 'standard', 'discount_percent' => 0, 'priority' => 0,
                'description' => 'Default wholesale price list.'],
            ['name' => 'Silver', 'slug' => 'silver', 'discount_percent' => 5, 'priority' => 10,
                'description' => 'Annual volume above 100 000 SEK.'],
            ['name' => 'Guld', 'slug' => 'gold', 'discount_percent' => 10, 'priority' => 20,
                'description' => 'Annual volume above 500 000 SEK.'],
            ['name' => 'Partner', 'slug' => 'partner', 'discount_percent' => 18, 'priority' => 30,
                'description' => 'Strategic retail partners with framework agreements.'],
        ])->mapWithKeys(fn (array $t) => [$t['slug'] => B2bPriceTier::create($t)]);

        $campaign = B2bPriceTier::create([
            'name' => 'Julkampanj Q4', 'slug' => 'q4-campaign', 'discount_percent' => 12, 'priority' => 25,
            'description' => 'Time-boxed holiday campaign.',
        ]);

        // 40 customers, most on the standard list, a few partners.
        $customers = B2bCustomer::factory()->count(36)->create()
            ->concat(B2bCustomer::factory()->count(4)->unmatched()->create());

        $weights = ['standard' => 18, 'silver' => 12, 'gold' => 7, 'partner' => 3];
        $pool = collect($weights)->flatMap(fn (int $n, string $slug) => array_fill(0, $n, $slug))->shuffle();

        $customers->each(function (B2bCustomer $customer, int $i) use ($tiers, $pool) {
            $customer->priceTiers()->attach($tiers[$pool[$i]]);
        });

        $customers->random(3)->each(fn (B2bCustomer $c) => $c->priceTiers()->attach($campaign, [
            'valid_from' => now()->startOfQuarter(),
            'valid_until' => now()->endOfQuarter(),
        ]));

        // 60 SKUs with buffered stock, 6 of them running low.
        $skus = ProductSku::factory()->count(60)->create();
        $skus->each(function (ProductSku $sku, int $i) {
            InventoryCache::factory()->when($i < 6, fn ($f) => $f->lowStock())->create(['product_sku_id' => $sku->id]);
        });

        // 30 orders priced with each customer's best tier.
        $active = $customers->where('is_active', true)->load('priceTiers')->values();
        $statuses = array_merge(
            array_fill(0, 22, OrderLog::STATUS_SYNCED),
            array_fill(0, 3, OrderLog::STATUS_FAILED),
            array_fill(0, 3, OrderLog::STATUS_PRICED),
            array_fill(0, 2, OrderLog::STATUS_RECEIVED),
        );

        foreach ($statuses as $status) {
            $customer = $active->random();
            $tier = $customer->priceTiers->sortByDesc('priority')->first();
            $lines = $skus->where('is_active', true)->random(fake()->numberBetween(1, 5))
                ->map(fn (ProductSku $sku) => [
                    'sku' => $sku->sku,
                    'quantity' => $qty = fake()->randomElement([6, 12, 24, 48]),
                    'base_price' => (float) $sku->base_price,
                    'tier' => $tier->slug,
                    'unit_price' => $unit = round($sku->base_price * (1 - $tier->discount_percent / 100), 2),
                    'line_total' => round($unit * $qty, 2),
                ])->values()->all();
            $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);

            $factory = OrderLog::factory()->for($customer, 'customer');
            $factory = match ($status) {
                OrderLog::STATUS_SYNCED => $factory->synced(),
                OrderLog::STATUS_FAILED => $factory->failed(),
                default => $factory->state(['status' => $status]),
            };

            $order = $factory->create([
                'subtotal' => $subtotal,
                'total' => round($subtotal * 1.25, 2),
                'priced_lines' => $status === OrderLog::STATUS_RECEIVED ? null : $lines,
            ]);

            $job = SyncJob::factory()->for($order, 'syncable');
            match ($status) {
                OrderLog::STATUS_SYNCED => $job->create(),
                OrderLog::STATUS_FAILED => $job->failed(fake()->numberBetween(1, 5))->create(['last_error' => $order->last_error]),
                default => $job->create(['status' => SyncJob::STATUS_PENDING, 'attempts' => 0, 'started_at' => null, 'finished_at' => null]),
            };
        }

        // Recent inventory pushes to Woo.
        $skus->random(12)->each(fn (ProductSku $sku) => SyncJob::factory()->for($sku, 'syncable')->create([
            'type' => SyncJob::TYPE_INVENTORY_SYNC,
        ]));
    }
}
