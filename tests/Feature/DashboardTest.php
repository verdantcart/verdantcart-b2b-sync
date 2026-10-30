<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use Database\Seeders\B2bDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_with_an_empty_database(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('No tiers yet');
    }

    public function test_dashboard_counts_only_active_customers_per_tier(): void
    {
        $gold = B2bPriceTier::factory()->create(['name' => 'Guld', 'discount_percent' => 10]);
        B2bCustomer::factory()->count(3)->create(['is_active' => true])
            ->each(fn ($c) => $c->priceTiers()->attach($gold));
        B2bCustomer::factory()->inactive()->create()->priceTiers()->attach($gold);

        $response = $this->get('/')->assertOk();

        $tier = $response->viewData('tiers')->firstWhere('name', 'Guld');
        $this->assertSame(3, $tier->customers_count);
        $this->assertSame(3, $response->viewData('stats')['customers']);
    }

    public function test_expired_tier_assignments_are_not_counted(): void
    {
        $campaign = B2bPriceTier::factory()->create(['name' => 'Kampanj']);
        B2bCustomer::factory()->create(['is_active' => true])
            ->priceTiers()->attach($campaign, ['valid_until' => now()->subDay()]);

        $tier = $this->get('/')->viewData('tiers')->firstWhere('name', 'Kampanj');

        $this->assertSame(0, $tier->customers_count);
    }

    public function test_demo_seeder_produces_a_consistent_dataset(): void
    {
        $this->seed(B2bDemoSeeder::class);

        $this->assertSame(40, B2bCustomer::count());
        $this->assertSame(5, B2bPriceTier::count());
        $this->get('/')->assertOk()->assertSee('Partner')->assertSee('synced');
    }
}
