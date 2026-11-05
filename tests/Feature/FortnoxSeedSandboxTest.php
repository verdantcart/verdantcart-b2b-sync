<?php

namespace Tests\Feature;

use App\Erp\Fortnox\FortnoxClient;
use App\Models\B2bCustomer;
use App\Models\ErpConnection;
use App\Models\InventoryCache;
use App\Models\ProductSku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FortnoxSeedSandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'erp.fortnox.client_id' => 'c', 'erp.fortnox.client_secret' => 's',
            'services.woocommerce.base_url' => 'https://shop.example.se',
            'services.woocommerce.consumer_key' => 'ck', 'services.woocommerce.consumer_secret' => 'cs',
        ]);
        $this->app->forgetInstance(FortnoxClient::class);
        ErpConnection::create(['driver' => 'fortnox', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'connected_at' => now()]);
    }

    public function test_creates_missing_customers_and_articles_and_skips_existing_ones(): void
    {
        B2bCustomer::factory()->create(['woo_customer_id' => 3, 'fortnox_customer_number' => '1042', 'company_name' => 'Lundberg AB', 'email' => 'inkop@lundberg.se']);
        B2bCustomer::factory()->create(['fortnox_customer_number' => '9999', 'email' => 'not@inshop.se']); // not in the shop: ignored
        ProductSku::factory()->create(['fortnox_article_number' => 'LOCAL1', 'sku' => 'VC-LOCAL-ONLY']); // not in the shop: ignored
        $sku = ProductSku::factory()->create(['sku' => 'VC-TXT-5432', 'woo_product_id' => 17, 'fortnox_article_number' => 'TXT5432', 'name' => 'Ullpläd grå']);
        InventoryCache::factory()->create(['product_sku_id' => $sku->id, 'warehouse_quantity' => 40]);
        ProductSku::factory()->create(['sku' => 'VC-TEA-1627', 'woo_product_id' => 18, 'fortnox_article_number' => 'TEA1627']);

        Http::fake(function (Request $r) {
            return match (true) {
                str_contains($r->url(), 'shop.example.se') && str_contains($r->url(), '/products') => Http::response([['sku' => 'VC-TXT-5432'], ['sku' => 'VC-TEA-1627']], 200, ['X-WP-TotalPages' => '1']),
                str_contains($r->url(), 'shop.example.se') && str_contains($r->url(), '/customers') => Http::response([['email' => 'INKOP@lundberg.se']], 200, ['X-WP-TotalPages' => '1']),
                str_ends_with($r->url(), '/companyinformation') => Http::response(['CompanyInformation' => ['CompanyName' => 'VerdantCart B2B Sync', 'OrganizationNumber' => '555555-5555']]),
                str_ends_with($r->url(), '/articles/TEA1627') => Http::response(['Article' => ['ArticleNumber' => 'TEA1627']]),
                $r->method() === 'GET' => Http::response(['ErrorInformation' => ['message' => 'Kan inte hitta', 'code' => 2000433]], 404),
                default => Http::response([], 201),
            };
        });

        $this->artisan('fortnox:seed-sandbox')
            ->expectsOutputToContain('VerdantCart B2B Sync (555555-5555)')
            ->expectsOutputToContain('up to 1 customers and 2 articles')
            ->expectsConfirmation('Is VerdantCart B2B Sync (555555-5555) a TEST company you want demo data in?', 'yes')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/customers')
            && $r['Customer']['CustomerNumber'] === '1042' && $r['Customer']['Type'] === 'COMPANY');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/articles')
            && $r['Article'] === ['ArticleNumber' => 'TXT5432', 'Description' => 'Ullpläd grå', 'StockGoods' => true, 'QuantityInStock' => '40']);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && ($r['Article']['ArticleNumber'] ?? null) === 'TEA1627');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && ($r['Customer']['CustomerNumber'] ?? null) === '9999');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && ($r['Article']['ArticleNumber'] ?? null) === 'LOCAL1');
    }

    public function test_writes_nothing_without_confirmation(): void
    {
        Http::fake([
            'shop.example.se/*' => Http::response([], 200, ['X-WP-TotalPages' => '1']),
            '*/companyinformation' => Http::response(['CompanyInformation' => ['CompanyName' => 'Real AB']]),
            '*' => Http::response([], 201),
        ]);

        $this->artisan('fortnox:seed-sandbox')
            ->expectsConfirmation('Is Real AB (?) a TEST company you want demo data in?', 'no')
            ->assertFailed();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }
}
