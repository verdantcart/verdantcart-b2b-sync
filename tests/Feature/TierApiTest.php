<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use App\Models\ProductSku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TierApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(array $abilities = ['*']): void
    {
        Sanctum::actingAs(User::factory()->create(), $abilities);
    }

    public function test_every_endpoint_requires_a_token(): void
    {
        $this->getJson('/api/tiers')->assertUnauthorized();
        $this->postJson('/api/tiers', ['name' => 'Guld', 'discount_percent' => 10])->assertUnauthorized();
        $this->postJson('/api/customers/1/tier', ['tier' => 'gold'])->assertUnauthorized();
        $this->getJson('/api/customers/1/price/VC-TEA-1')->assertUnauthorized();
    }

    public function test_token_abilities_are_enforced(): void
    {
        $this->actingWith(['prices:read']);

        $this->postJson('/api/tiers', ['name' => 'Guld', 'discount_percent' => 10])->assertForbidden();
        $this->getJson('/api/tiers')->assertForbidden();
    }

    public function test_create_tier_derives_slug_and_returns_201(): void
    {
        $this->actingWith(['tiers:write']);

        $this->postJson('/api/tiers', ['name' => 'Guld Plus', 'discount_percent' => '12.5', 'priority' => 25])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'guld-plus')
            ->assertJsonPath('data.discount_percent', '12.50')
            ->assertJsonPath('data.priority', 25)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('b2b_price_tiers', ['slug' => 'guld-plus', 'discount_percent' => 12.5]);
    }

    public function test_create_tier_validates_input(): void
    {
        $this->actingWith(['tiers:write']);
        B2bPriceTier::factory()->create(['slug' => 'gold']);

        $this->postJson('/api/tiers', ['name' => 'X', 'slug' => 'gold', 'discount_percent' => 150])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug', 'discount_percent']);

        $this->postJson('/api/tiers', ['discount_percent' => '10.123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'discount_percent']);
    }

    public function test_list_tiers_includes_customer_counts(): void
    {
        $this->actingWith(['tiers:read']);
        $gold = B2bPriceTier::factory()->create(['slug' => 'gold', 'priority' => 20]);
        B2bCustomer::factory()->count(2)->create()->each(fn ($c) => $c->priceTiers()->attach($gold));

        $this->getJson('/api/tiers')->assertOk()
            ->assertJsonPath('data.0.slug', 'gold')
            ->assertJsonPath('data.0.customers_count', 2);
    }

    public function test_assign_tier_adds_membership_then_updates_it(): void
    {
        $this->actingWith(['customers:write']);
        $customer = B2bCustomer::factory()->create(['is_active' => true]);
        B2bPriceTier::factory()->create(['slug' => 'gold', 'priority' => 20]);

        $this->postJson("/api/customers/{$customer->id}/tier", ['tier' => 'gold'])
            ->assertCreated()
            ->assertJsonPath('data.effective_tier', 'gold');

        $this->postJson("/api/customers/{$customer->id}/tier", [
            'tier' => 'gold', 'valid_from' => '2026-01-01', 'valid_until' => '2026-03-31',
        ])->assertOk()->assertJsonPath('data.effective_tier', null); // window is in the past

        $this->assertSame(1, $customer->priceTiers()->count());
    }

    public function test_assign_tier_keeps_other_memberships_and_validates(): void
    {
        $this->actingWith(['customers:write']);
        $customer = B2bCustomer::factory()->create();
        $silver = B2bPriceTier::factory()->create(['slug' => 'silver']);
        B2bPriceTier::factory()->create(['slug' => 'gold']);
        $customer->priceTiers()->attach($silver);

        $this->postJson("/api/customers/{$customer->id}/tier", ['tier' => 'gold'])->assertCreated();
        $this->assertSame(2, $customer->priceTiers()->count());

        $this->postJson("/api/customers/{$customer->id}/tier", ['tier' => 'platinum'])
            ->assertUnprocessable()->assertJsonPath('errors.tier.0', 'No price tier with slug "platinum".');
        $this->postJson("/api/customers/{$customer->id}/tier", ['tier' => 'gold', 'valid_from' => '2026-05-01', 'valid_until' => '2026-04-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('valid_until');
        $this->postJson('/api/customers/99999/tier', ['tier' => 'gold'])->assertNotFound();
    }

    public function test_price_uses_the_highest_priority_active_tier(): void
    {
        $this->actingWith(['prices:read']);
        $customer = B2bCustomer::factory()->create(['is_active' => true]);
        $sku = ProductSku::factory()->create(['sku' => 'VC-TEA-1627', 'base_price' => '1431.59', 'currency' => 'SEK']);
        $silver = B2bPriceTier::factory()->create(['slug' => 'silver', 'discount_percent' => 5, 'priority' => 10]);
        $partner = B2bPriceTier::factory()->create(['slug' => 'partner', 'discount_percent' => 18, 'priority' => 30, 'is_active' => false]);
        $gold = B2bPriceTier::factory()->create(['slug' => 'gold', 'discount_percent' => 10, 'priority' => 20]);
        $customer->priceTiers()->attach([$silver->id, $partner->id, $gold->id]);

        $this->getJson("/api/customers/{$customer->id}/price/VC-TEA-1627")->assertOk()
            ->assertJson(['data' => [
                'sku' => 'VC-TEA-1627',
                'currency' => 'SEK',
                'base_price' => '1431.59',
                'unit_price' => '1288.43',
                'tier' => ['slug' => 'gold', 'discount_percent' => '10.00'],
            ]]);
    }

    public function test_price_ignores_expired_and_future_assignments(): void
    {
        $this->actingWith(['prices:read']);
        $customer = B2bCustomer::factory()->create(['is_active' => true]);
        ProductSku::factory()->create(['sku' => 'VC-KAF-1', 'base_price' => '200.00']);
        $campaign = B2bPriceTier::factory()->create(['slug' => 'q4', 'discount_percent' => 12, 'priority' => 50]);
        $next = B2bPriceTier::factory()->create(['slug' => 'next-year', 'discount_percent' => 30, 'priority' => 60]);
        $customer->priceTiers()->attach($campaign, ['valid_until' => now()->subDay()]);
        $customer->priceTiers()->attach($next, ['valid_from' => now()->addMonth()]);

        $this->getJson("/api/customers/{$customer->id}/price/VC-KAF-1")
            ->assertOk()->assertJsonPath('data.unit_price', '200.00')->assertJsonPath('data.tier', null);
    }

    public function test_inactive_customer_gets_list_price_and_unknown_sku_is_404(): void
    {
        $this->actingWith(['prices:read']);
        $customer = B2bCustomer::factory()->inactive()->create();
        ProductSku::factory()->create(['sku' => 'VC-LJS-1', 'base_price' => '99.00']);
        $gold = B2bPriceTier::factory()->create(['discount_percent' => 10]);
        $customer->priceTiers()->attach($gold);

        $this->getJson("/api/customers/{$customer->id}/price/VC-LJS-1")->assertOk()
            ->assertJsonPath('data.customer_active', false)
            ->assertJsonPath('data.unit_price', '99.00');

        $this->getJson("/api/customers/{$customer->id}/price/NOPE")->assertNotFound()
            ->assertJsonPath('message', 'Unknown SKU [NOPE].');
    }

    public function test_api_token_command_creates_a_scoped_token_that_works(): void
    {
        $this->artisan('api:token', ['name' => 'Postman', '--ability' => ['prices:read']])
            ->expectsOutputToContain('abilities: prices:read')
            ->assertSuccessful();

        $token = User::where('email', 'api@verdantcart-b2b-sync.local')->sole()->tokens()->sole();
        $this->assertSame(['prices:read'], $token->abilities);

        $this->artisan('api:token', ['name' => 'X', '--ability' => ['admin']])->assertFailed();
    }

    public function test_real_bearer_token_round_trip(): void
    {
        $plain = User::factory()->create()->createToken('test', ['tiers:read'])->plainTextToken;

        $this->withToken($plain)->getJson('/api/tiers')->assertOk();
        $this->app['auth']->forgetGuards(); // in a real app every request starts unauthenticated
        $this->withToken('1|not-a-real-token')->getJson('/api/tiers')->assertUnauthorized();
    }
}
