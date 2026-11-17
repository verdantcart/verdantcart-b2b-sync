<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use App\Models\ProductSku;
use App\Services\Pricing\PriceResolver;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        app()->detectEnvironment(fn () => 'local'); // admin pages are open locally without a password
        $this->withoutMiddleware(PreventRequestForgery::class); // CSRF itself is tested in SyncRetryTest
    }

    private function customerFields(array $overrides = []): array
    {
        return $overrides + [
            'company_name' => 'Acme AB', 'email' => 'inköp@acme.se', 'org_number' => '556000-0000',
            'country_code' => 'se', 'currency' => 'sek', 'is_active' => '1',
        ];
    }

    // --- Access ----------------------------------------------------------------------------

    public function test_tier_and_customer_pages_need_the_admin_login_outside_local(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['horizon.basic_auth.user' => 'admin', 'horizon.basic_auth.password' => 's3cret', 'b2bsync.public_dashboard' => true]);
        $customer = B2bCustomer::factory()->create();

        $urls = ['/tiers', '/customers', '/customers/create', "/customers/{$customer->id}"];
        foreach ($urls as $url) {
            $this->get($url)->assertUnauthorized();
        }
        foreach ($urls as $url) {
            $this->withBasicAuth('admin', 's3cret')->get($url)->assertOk();
        }
    }

    public function test_every_admin_page_shows_the_menu(): void
    {
        foreach (['/', '/tiers', '/customers', '/sync-jobs', '/setup'] as $url) {
            $this->get($url)->assertOk()->assertSee('aria-label="Main"', false)->assertSee('Customers')->assertSee('Tiers');
        }
    }

    // --- Tiers -----------------------------------------------------------------------------

    public function test_a_tier_can_be_created_with_a_swedish_decimal_comma(): void
    {
        $this->post('/tiers', ['name' => 'Återförsäljare Guld', 'discount_percent' => '12,5', 'priority' => 10, 'is_active' => '1'])
            ->assertRedirect('/tiers');

        $tier = B2bPriceTier::sole();
        $this->assertSame(['aterforsaljare-guld', '12.50', 10, true], [$tier->slug, $tier->discount_percent, $tier->priority, $tier->is_active]);
    }

    public function test_tier_list_shows_an_example_price_for_each_tier(): void
    {
        ProductSku::factory()->create(['sku' => 'TEA-100', 'base_price' => '200.00', 'is_active' => true]);
        B2bPriceTier::factory()->create(['name' => 'Silver', 'discount_percent' => 10]);

        $this->get('/tiers')->assertSee('Silver')->assertSee('TEA-100')->assertSee('180.00');
    }

    public function test_a_tier_can_be_edited_and_switched_off(): void
    {
        $tier = B2bPriceTier::factory()->create(['name' => 'Silver', 'slug' => 'silver', 'discount_percent' => 10, 'is_active' => true]);

        $this->put("/tiers/{$tier->id}", ['name' => 'Silver', 'slug' => 'silver', 'discount_percent' => '15', 'priority' => 1])
            ->assertRedirect("/tiers/{$tier->id}/edit");

        $tier->refresh();
        $this->assertSame(['15.00', false], [$tier->discount_percent, $tier->is_active], 'an unticked box means inactive');
    }

    public function test_tier_names_must_be_unique_and_discounts_sensible(): void
    {
        B2bPriceTier::factory()->create(['slug' => 'gold']);

        $this->post('/tiers', ['name' => 'Gold', 'discount_percent' => '10'])->assertSessionHasErrors('slug');
        $this->post('/tiers', ['name' => 'Other', 'discount_percent' => '120'])->assertSessionHasErrors('discount_percent');
        $this->post('/tiers', ['name' => 'Other', 'discount_percent' => 'a lot'])->assertSessionHasErrors('discount_percent');
    }

    public function test_a_tier_with_customers_cannot_be_deleted(): void
    {
        $used = B2bPriceTier::factory()->create(['name' => 'Used']);
        B2bCustomer::factory()->create()->priceTiers()->attach($used);
        $empty = B2bPriceTier::factory()->create();

        $this->from("/tiers/{$used->id}/edit")->delete("/tiers/{$used->id}")->assertSessionHas('error');
        $this->delete("/tiers/{$empty->id}")->assertRedirect('/tiers');

        $this->assertModelExists($used);
        $this->assertModelMissing($empty);
    }

    // --- Customer list ---------------------------------------------------------------------

    public function test_customers_can_be_searched_and_filtered(): void
    {
        $gold = B2bPriceTier::factory()->create(['name' => 'Gold', 'slug' => 'gold']);
        B2bCustomer::factory()->create(['company_name' => 'Nordic Tea AB', 'woo_customer_id' => 7, 'fortnox_customer_number' => '1001'])->priceTiers()->attach($gold);
        B2bCustomer::factory()->create(['company_name' => 'Fika Butiken', 'woo_customer_id' => null, 'fortnox_customer_number' => null]);

        $names = fn (string $url) => collect($this->get($url)->assertOk()->viewData('customers')->items())->pluck('company_name')->all();

        $this->assertSame(['Nordic Tea AB'], $names('/customers?q=nordic'));
        $this->assertSame(['Nordic Tea AB'], $names('/customers?q=7'), 'a number finds the Woo customer ID');
        $this->assertSame(['Nordic Tea AB'], $names('/customers?q=1001'), 'and the Fortnox customer number');
        $this->assertSame(['Nordic Tea AB'], $names('/customers?tier=gold'));
        $this->assertSame(['Fika Butiken'], $names('/customers?filter=no-tier'));
        $this->assertSame(['Fika Butiken'], $names('/customers?filter=no-woo'));
        $this->assertSame(['Fika Butiken'], $names('/customers?filter=no-fortnox'));
    }

    public function test_the_list_shows_the_tier_each_customer_actually_gets(): void
    {
        $silver = B2bPriceTier::factory()->create(['name' => 'Silver', 'priority' => 1]);
        $campaign = B2bPriceTier::factory()->create(['name' => 'Spring campaign', 'priority' => 9]);
        $customer = B2bCustomer::factory()->create(['is_active' => true]);
        $customer->priceTiers()->attach($silver);
        $customer->priceTiers()->attach($campaign, ['valid_until' => now()->subDay()]);

        $this->get('/customers')->assertSee('<td>Silver', false)->assertDontSee('<td>Spring campaign', false);
        $this->assertTrue(app(PriceResolver::class)->effectiveTier($customer, now())->is($silver), 'list and pricing agree');
    }

    // --- Create and edit -------------------------------------------------------------------

    public function test_a_customer_can_be_added(): void
    {
        $response = $this->post('/customers', $this->customerFields(['woo_customer_id' => ' 42 ']));

        $customer = B2bCustomer::sole();
        $response->assertRedirect("/customers/{$customer->id}");
        $this->assertSame(['SE', 'SEK', 42, null, true], [$customer->country_code, $customer->currency, $customer->woo_customer_id, $customer->fortnox_customer_number, $customer->is_active]);
    }

    public function test_a_woocommerce_or_fortnox_customer_can_only_be_linked_once(): void
    {
        B2bCustomer::factory()->create(['company_name' => 'First', 'woo_customer_id' => 42, 'fortnox_customer_number' => 'F1']);
        $second = B2bCustomer::factory()->create(['woo_customer_id' => null, 'fortnox_customer_number' => null]);

        $this->put("/customers/{$second->id}", $this->customerFields(['woo_customer_id' => 42, 'fortnox_customer_number' => 'F1']))
            ->assertSessionHasErrors(['woo_customer_id', 'fortnox_customer_number']);
    }

    public function test_saving_a_customer_keeps_its_own_links(): void
    {
        $customer = B2bCustomer::factory()->create(['woo_customer_id' => 42, 'fortnox_customer_number' => 'F1']);

        $this->put("/customers/{$customer->id}", $this->customerFields(['woo_customer_id' => 42, 'fortnox_customer_number' => 'F1', 'is_active' => null]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($customer->fresh()->is_active);
    }

    // --- Tiers on the customer page --------------------------------------------------------

    public function test_a_customer_can_be_put_in_a_tier_and_sees_its_prices(): void
    {
        ProductSku::factory()->create(['sku' => 'TEA-100', 'base_price' => '200.00', 'is_active' => true]);
        $gold = B2bPriceTier::factory()->create(['name' => 'Gold', 'discount_percent' => 25]);
        $customer = B2bCustomer::factory()->create(['is_active' => true]);

        $this->get("/customers/{$customer->id}")->assertSee('no tier · pays list price')->assertSee('200.00');

        $this->post("/customers/{$customer->id}/tiers", ['tier_id' => $gold->id])->assertRedirect("/customers/{$customer->id}");

        $this->get("/customers/{$customer->id}")->assertSee('Gold · 25 % off')->assertSee('150.00');
    }

    public function test_a_campaign_tier_lasts_up_to_and_including_its_end_date(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00');
        $campaign = B2bPriceTier::factory()->create();
        $customer = B2bCustomer::factory()->create();

        $this->post("/customers/{$customer->id}/tiers", ['tier_id' => $campaign->id, 'valid_from' => '2026-03-01', 'valid_until' => '2026-03-31']);

        $until = Carbon::parse($customer->priceTiers()->sole()->pivot->valid_until);
        $this->assertTrue($until->equalTo(Carbon::parse('2026-04-01 00:00:00', config('app.display_timezone'))), 'midnight in the shop\'s time zone');
        $this->get("/customers/{$customer->id}")->assertSee('2026-03-01 – 2026-03-31');
    }

    public function test_tier_dates_must_make_sense(): void
    {
        $tier = B2bPriceTier::factory()->create();
        $customer = B2bCustomer::factory()->create();

        $this->post("/customers/{$customer->id}/tiers", ['tier_id' => $tier->id, 'valid_from' => '2030-05-01', 'valid_until' => '2030-04-01'])
            ->assertSessionHasErrorsIn('tier', 'valid_until');
        $this->assertSame(0, $customer->priceTiers()->count());
    }

    public function test_a_customer_can_be_taken_out_of_a_tier(): void
    {
        $tier = B2bPriceTier::factory()->create(['name' => 'Gold']);
        $customer = B2bCustomer::factory()->create();
        $customer->priceTiers()->attach($tier);

        $this->delete("/customers/{$customer->id}/tiers/{$tier->id}")->assertSessionHas('status', 'Removed from Gold.');
        $this->assertSame(0, $customer->priceTiers()->count());
    }

    // --- Find in WooCommerce ---------------------------------------------------------------

    public function test_find_in_woocommerce_links_the_customer_at_once(): void
    {
        config(['services.woocommerce.base_url' => 'https://shop.acme.test', 'services.woocommerce.consumer_key' => 'ck', 'services.woocommerce.consumer_secret' => 'cs']);
        $this->app->forgetInstance(WooCommerceClient::class);
        Http::preventStrayRequests();
        Http::fake(['shop.acme.test/wp-json/wc/v3/customers*' => Http::response([['id' => 77, 'first_name' => 'Sara', 'last_name' => 'Lind']])]);
        $customer = B2bCustomer::factory()->create(['email' => 'sara@acme.se', 'woo_customer_id' => null]);

        $this->post("/customers/{$customer->id}/find-in-woo")
            ->assertRedirect("/customers/{$customer->id}")
            ->assertSessionHas('status', fn (string $s) => str_starts_with($s, 'Linked to WooCommerce customer 77 (Sara Lind).'));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'email=sara%40acme.se'));
        $this->assertSame(77, $customer->fresh()->woo_customer_id, 'no separate Save needed');
    }

    public function test_find_in_woocommerce_never_steals_a_link(): void
    {
        config(['services.woocommerce.base_url' => 'https://shop.acme.test', 'services.woocommerce.consumer_key' => 'ck', 'services.woocommerce.consumer_secret' => 'cs']);
        $this->app->forgetInstance(WooCommerceClient::class);
        Http::fake(['*' => Http::response([['id' => 77]])]);
        B2bCustomer::factory()->create(['company_name' => 'First AB', 'woo_customer_id' => 77]);
        $second = B2bCustomer::factory()->create(['woo_customer_id' => null]);

        $this->from("/customers/{$second->id}")->post("/customers/{$second->id}/find-in-woo")
            ->assertSessionHas('error', 'WooCommerce customer 77 is already linked to First AB.');
        $this->assertNull($second->fresh()->woo_customer_id);
    }

    public function test_find_in_woocommerce_explains_when_nobody_matches(): void
    {
        config(['services.woocommerce.base_url' => 'https://shop.acme.test', 'services.woocommerce.consumer_key' => 'ck', 'services.woocommerce.consumer_secret' => 'cs']);
        $this->app->forgetInstance(WooCommerceClient::class);
        Http::fake(['*' => Http::response([])]);
        $customer = B2bCustomer::factory()->create(['email' => 'nobody@acme.se']);

        $this->from("/customers/{$customer->id}")->post("/customers/{$customer->id}/find-in-woo")
            ->assertSessionHas('error', fn (string $e) => str_contains($e, 'nobody@acme.se'));
    }
}
