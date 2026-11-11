<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\User;
use App\Services\Orders\OrderPricer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StorefrontPriceApiTest extends TestCase
{
    use RefreshDatabase;

    private B2bCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        ProductSku::factory()->create(['sku' => 'VC-KAF-4735', 'base_price' => '180.00', 'currency' => 'SEK']);
        ProductSku::factory()->create(['sku' => 'VC-TEA-1627', 'base_price' => '1431.59', 'currency' => 'SEK']);

        $this->customer = B2bCustomer::factory()->create(['woo_customer_id' => 42, 'is_active' => true]);
        $gold = B2bPriceTier::factory()->create(['slug' => 'gold', 'name' => 'Guld', 'discount_percent' => 10, 'priority' => 20]);
        $this->customer->priceTiers()->attach($gold);
    }

    private function actingWith(array $abilities = ['prices:read']): void
    {
        Sanctum::actingAs(User::factory()->create(), $abilities);
    }

    public function test_requires_a_token_with_the_prices_ability(): void
    {
        $this->getJson('/api/woo-customers/42/prices?skus=VC-KAF-4735')->assertUnauthorized();

        $this->actingWith(['tiers:read']);
        $this->getJson('/api/woo-customers/42/prices?skus=VC-KAF-4735')->assertForbidden();
    }

    public function test_returns_tier_prices_for_many_skus_and_lists_unknown_ones(): void
    {
        $this->actingWith();

        $this->getJson('/api/woo-customers/42/prices?skus=VC-KAF-4735, VC-TEA-1627,NOPE,VC-KAF-4735')
            ->assertOk()
            ->assertJsonPath('data.is_b2b', true)
            ->assertJsonPath('data.tier.slug', 'gold')
            ->assertJsonPath('data.prices.VC-KAF-4735.unit_price', '162.00')
            ->assertJsonPath('data.prices.VC-KAF-4735.base_price', '180.00')
            ->assertJsonPath('data.prices.VC-TEA-1627.unit_price', '1288.43')
            ->assertJsonPath('data.prices.VC-TEA-1627.currency', 'SEK')
            ->assertJsonPath('data.missing', ['NOPE'])
            ->assertJsonPath('meta.cache_ttl', 300);
    }

    public function test_tier_is_looked_up_once_however_many_skus_are_asked_for(): void
    {
        ProductSku::factory()->count(40)->create();
        $skus = ProductSku::pluck('sku')->implode(',');
        $this->actingWith();

        DB::enableQueryLog();
        $this->getJson('/api/woo-customers/42/prices?skus='.urlencode($skus))->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')
            ->reject(fn (string $sql) => str_contains($sql, 'personal_access_tokens') || str_contains($sql, '"users"'));

        $this->assertLessThanOrEqual(3, $queries->count(), $queries->implode("\n"));
    }

    public function test_unknown_or_inactive_customer_is_not_b2b_so_the_shop_keeps_its_own_price(): void
    {
        $this->actingWith();

        $this->getJson('/api/woo-customers/999/prices?skus=VC-KAF-4735')->assertOk()
            ->assertJsonPath('data.is_b2b', false)
            ->assertJsonPath('data.prices', [])
            ->assertJsonPath('data.tier', null);

        $this->customer->update(['is_active' => false]);
        $this->getJson('/api/woo-customers/42/prices?skus=VC-KAF-4735')->assertOk()
            ->assertJsonPath('data.is_b2b', false);
    }

    public function test_b2b_customer_without_a_valid_tier_gets_list_price(): void
    {
        $this->customer->priceTiers()->detach();
        $this->actingWith();

        $this->getJson('/api/woo-customers/42/prices?skus=VC-KAF-4735')->assertOk()
            ->assertJsonPath('data.is_b2b', true)
            ->assertJsonPath('data.tier', null)
            ->assertJsonPath('data.prices.VC-KAF-4735.unit_price', '180.00');
    }

    public function test_validates_the_sku_list(): void
    {
        $this->actingWith();

        $this->getJson('/api/woo-customers/42/prices')->assertUnprocessable()->assertJsonValidationErrors('skus');
        $this->getJson('/api/woo-customers/42/prices?skus=,,')->assertUnprocessable()->assertJsonValidationErrors('skus');

        $tooMany = collect(range(1, 101))->map(fn ($i) => "SKU-{$i}")->implode(',');
        $this->getJson('/api/woo-customers/42/prices?skus='.$tooMany)->assertUnprocessable()
            ->assertJsonPath('errors.skus.0', 'At most 100 SKUs per request.');

        $this->getJson('/api/woo-customers/42/prices?skus='.str_repeat('X', 101))->assertUnprocessable();
        $this->getJson('/api/woo-customers/abc/prices?skus=VC-KAF-4735')->assertNotFound();
    }

    public function test_shop_price_matches_the_price_sent_to_fortnox(): void
    {
        $this->actingWith();
        $shop = $this->getJson('/api/woo-customers/42/prices?skus=VC-KAF-4735,VC-TEA-1627')->json('data.prices');

        $order = OrderLog::factory()->create([
            'b2b_customer_id' => $this->customer->id,
            'received_at' => now(),
            'woo_payload' => ['line_items' => [
                ['id' => 1, 'sku' => 'VC-KAF-4735', 'product_id' => 1, 'quantity' => 24, 'subtotal' => '3888.00'],
                ['id' => 2, 'sku' => 'VC-TEA-1627', 'product_id' => 2, 'quantity' => 3, 'subtotal' => '3865.29'],
            ]],
        ]);

        $lines = collect(app(OrderPricer::class)->price($order)['lines'])->keyBy('sku');

        foreach ($shop as $sku => $price) {
            $this->assertSame($price['unit_price'], $lines[$sku]['unit_price'], "Shop and Fortnox disagree on {$sku}");
            $this->assertSame('0.00', $lines[$sku]['difference'], "Woo charged a different total for {$sku}");
        }
    }
}
