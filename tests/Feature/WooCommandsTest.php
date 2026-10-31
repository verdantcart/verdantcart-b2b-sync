<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\InventoryCache;
use App\Models\ProductSku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class WooCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config([
            'services.woocommerce.base_url' => 'http://woo-b2b.test',
            'services.woocommerce.consumer_key' => 'ck_test',
            'services.woocommerce.consumer_secret' => 'cs_test',
        ]);
    }

    public function test_ping_reports_store_counts(): void
    {
        Http::fake([
            '*/settings/general*' => Http::response([['id' => 'woocommerce_currency', 'value' => 'SEK']]),
            '*/products*' => Http::response([], 200, ['X-WP-Total' => '3']),
            '*/customers*' => Http::response([], 200, ['X-WP-Total' => '2']),
            '*/orders*' => Http::response([], 200, ['X-WP-Total' => '0']),
        ]);

        $this->artisan('woo:ping')
            ->expectsOutputToContain('OAuth 1.0a (http)')
            ->expectsOutputToContain('SEK')
            ->expectsOutputToContain('API is reachable')
            ->assertSuccessful();
    }

    public function test_ping_explains_a_bad_key(): void
    {
        Http::fake(['*' => Http::response(['code' => 'woocommerce_rest_authentication_error', 'message' => 'Invalid signature - provided signature does not match.'], 401)]);

        $this->artisan('woo:ping')
            ->expectsOutputToContain('Invalid signature')
            ->expectsOutputToContain('WOO_CONSUMER_KEY')
            ->assertFailed();
    }

    public function test_ping_explains_missing_configuration(): void
    {
        config(['services.woocommerce.consumer_key' => null]);

        $this->artisan('woo:ping')->expectsOutputToContain('WOO_CONSUMER_KEY')->assertFailed();
    }

    public function test_seed_demo_creates_missing_items_links_existing_ones_and_stores_woo_ids(): void
    {
        $linked = ProductSku::factory()->create(['sku' => 'VC-KAF-1000', 'is_active' => true]);
        $new = ProductSku::factory()->create(['sku' => 'VC-TEA-2000', 'is_active' => true]);
        InventoryCache::factory()->create(['product_sku_id' => $new->id, 'warehouse_quantity' => 40, 'reserved_quantity' => 0, 'buffer_quantity' => 5]);
        $customer = B2bCustomer::factory()->create(['is_active' => true, 'email' => 'inkop@lindberg.se']);

        Http::fake([
            '*/products/batch*' => Http::response(['create' => [['id' => 502, 'sku' => 'VC-TEA-2000']]]),
            '*/products*' => Http::response([['id' => 501, 'sku' => 'VC-KAF-1000']]),
            '*/customers/batch*' => Http::response(['create' => [['id' => 9, 'email' => 'inkop@lindberg.se']]]),
            '*/customers*' => Http::response([]),
        ]);

        $this->artisan('woo:seed-demo', ['--products' => 2, '--customers' => 1])->assertSuccessful();

        $this->assertSame(501, $linked->fresh()->woo_product_id);
        $this->assertSame(502, $new->fresh()->woo_product_id);
        $this->assertSame(9, $customer->fresh()->woo_customer_id);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'products/batch')
            && count($r['create']) === 1
            && $r['create'][0]['sku'] === 'VC-TEA-2000'
            && $r['create'][0]['stock_quantity'] === 35);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'customers/batch')
            && $r['create'][0]['billing']['company'] === $customer->company_name);
    }

    public function test_seed_demo_asks_before_writing_to_a_non_local_store(): void
    {
        config(['services.woocommerce.base_url' => 'https://real-shop.se']);
        Http::fake();

        $this->artisan('woo:seed-demo')
            ->expectsConfirmation('https://real-shop.se does not look like a local test store. Create demo data there anyway?', 'no')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_webhooks_command_generates_secret_in_env_and_creates_webhook(): void
    {
        $dir = sys_get_temp_dir().'/vc-env-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/.env', "APP_NAME=Test\n   WOO_WEBHOOK_SECRET=\n");
        $this->app->useEnvironmentPath($dir);
        config(['services.woocommerce.webhook_secret' => null, 'app.url' => 'http://localhost:8000']);

        Http::fake(fn (Request $r) => $r->method() === 'GET'
            ? Http::response([])                                  // no webhooks yet
            : Http::response(['id' => 7, 'status' => 'active']));

        $this->artisan('woo:webhooks')
            ->expectsOutputToContain('Generated WOO_WEBHOOK_SECRET')
            ->expectsOutputToContain('http://127.0.0.1:8000/webhooks/woocommerce')
            ->assertSuccessful();

        preg_match('/^WOO_WEBHOOK_SECRET=(\S+)$/m', file_get_contents($dir.'/.env'), $m);
        $this->assertSame(48, strlen($m[1] ?? ''), '.env should contain the generated secret on its own line');
        $this->assertSame(1, substr_count(file_get_contents($dir.'/.env'), 'WOO_WEBHOOK_SECRET='));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['topic'] === 'order.created'
            && $r['delivery_url'] === 'http://127.0.0.1:8000/webhooks/woocommerce'
            && $r['secret'] === $m[1]);
    }

    public function test_webhooks_command_updates_an_existing_webhook_instead_of_duplicating(): void
    {
        config(['services.woocommerce.webhook_secret' => 'existing-secret']);
        Http::fake([
            '*/webhooks/3*' => Http::response(['id' => 3, 'status' => 'active']),
            '*/webhooks*' => Http::response([[
                'id' => 3, 'topic' => 'order.created', 'status' => 'disabled',
                'delivery_url' => 'http://127.0.0.1:8000/webhooks/woocommerce',
            ]]),
        ]);

        $this->artisan('woo:webhooks', ['--url' => 'http://127.0.0.1:8000/webhooks/woocommerce'])
            ->expectsOutputToContain('updated')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['status'] === 'active' && $r['secret'] === 'existing-secret');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }
}
