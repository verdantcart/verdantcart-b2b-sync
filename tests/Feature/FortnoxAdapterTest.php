<?php

namespace Tests\Feature;

use App\Erp\ErpAdapter;
use App\Erp\ErpException;
use App\Erp\Fortnox\FortnoxAdapter;
use App\Erp\Fortnox\FortnoxClient;
use App\Jobs\SyncInventory;
use App\Jobs\SyncOrderToErp;
use App\Models\B2bCustomer;
use App\Models\ErpConnection;
use App\Models\InventoryCache;
use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\SyncJob;
use App\Services\Inventory\InventorySyncRequester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class FortnoxAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config([
            'erp.driver' => 'fortnox',
            'erp.fortnox.client_id' => 'client-123',
            'erp.fortnox.client_secret' => 'secret-456',
            'erp.fortnox.redirect_uri' => 'http://127.0.0.1:8000/fortnox/callback',
        ]);
        $this->app->forgetInstance(FortnoxClient::class);
    }

    private function connected(array $overrides = []): ErpConnection
    {
        return ErpConnection::create($overrides + [
            'driver' => 'fortnox', 'access_token' => 'access-1', 'refresh_token' => 'refresh-1',
            'expires_at' => now()->addHour(), 'connected_at' => now(),
        ]);
    }

    // --- OAuth -------------------------------------------------------------

    public function test_fortnox_login_routes_are_admin_only_outside_local(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['horizon.basic_auth.password' => null]);

        $this->get('/fortnox/connect')->assertForbidden();
        $this->get('/fortnox/callback?code=x&state=y')->assertForbidden();
    }

    public function test_connect_redirects_to_fortnox_with_state_and_scopes(): void
    {
        app()->detectEnvironment(fn () => 'local');
        $response = $this->get('/fortnox/connect')->assertRedirect();

        $url = $response->headers->get('Location');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertStringStartsWith('https://apps.fortnox.se/oauth-v1/auth?', $url);
        $this->assertSame(['client-123', 'http://127.0.0.1:8000/fortnox/callback', 'code', 'offline'],
            [$q['client_id'], $q['redirect_uri'], $q['response_type'], $q['access_type']]);
        $this->assertStringContainsString('order', $q['scope']);
        $this->assertSame(session('fortnox_oauth_state'), $q['state']);
    }

    public function test_callback_exchanges_the_code_and_stores_encrypted_tokens(): void
    {
        app()->detectEnvironment(fn () => 'local');
        Http::fake(['apps.fortnox.se/oauth-v1/token' => Http::response([
            'access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'scope' => 'order customer',
        ])]);

        $this->withSession(['fortnox_oauth_state' => 'abc'])
            ->get('/fortnox/callback?code=the-code&state=abc')
            ->assertRedirect(route('dashboard'));

        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'authorization_code' && $r['code'] === 'the-code'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('client-123:secret-456')));

        $connection = ErpConnection::sole();
        $this->assertSame(['new-access', 'new-refresh'], [$connection->access_token, $connection->refresh_token]);
        $raw = \DB::table('erp_connections')->value('refresh_token');
        $this->assertNotSame('new-refresh', $raw, 'tokens must be encrypted at rest');
    }

    public function test_callback_rejects_a_wrong_state(): void
    {
        app()->detectEnvironment(fn () => 'local');
        Http::fake();

        $this->withSession(['fortnox_oauth_state' => 'abc'])->get('/fortnox/callback?code=x&state=evil')->assertStatus(400);

        Http::assertNothingSent();
        $this->assertSame(0, ErpConnection::count());
    }

    public function test_expired_access_token_is_refreshed_and_the_rotated_refresh_token_saved(): void
    {
        $this->connected(['expires_at' => now()->subMinute()]);
        Http::fake([
            'apps.fortnox.se/oauth-v1/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]),
            'api.fortnox.se/*' => Http::response(['CompanyInformation' => ['CompanyName' => 'Test AB']]),
        ]);

        app(FortnoxClient::class)->get('companyinformation');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth-v1/token') && $r['refresh_token'] === 'refresh-1');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.fortnox.se') && $r->hasHeader('Authorization', 'Bearer access-2'));
        $this->assertSame('refresh-2', ErpConnection::sole()->refresh_token);
    }

    public function test_a_401_triggers_one_refresh_and_retry(): void
    {
        $this->connected();
        $calls = 0;
        Http::fake(function (Request $r) use (&$calls) {
            if (str_contains($r->url(), 'oauth-v1/token')) {
                return Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]);
            }

            return ++$calls === 1 ? Http::response(['message' => 'unauthorized'], 401) : Http::response(['Customers' => []]);
        });

        app(FortnoxClient::class)->get('customers');

        $this->assertSame(2, $calls);
    }

    public function test_revoked_login_gives_a_clear_reconnect_message(): void
    {
        $this->connected(['expires_at' => now()->subMinute()]);
        Http::fake(['apps.fortnox.se/oauth-v1/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid refresh token'], 400)]);

        $this->expectException(ErpException::class);
        $this->expectExceptionMessage('reconnect at /fortnox/connect');

        app(FortnoxClient::class)->get('customers');
    }

    public function test_not_connected_is_reported_clearly(): void
    {
        $this->expectExceptionMessage('Fortnox is not connected');

        app(FortnoxClient::class)->get('customers');
    }

    public function test_rate_limit_is_retried_with_backoff(): void
    {
        $this->connected();
        Http::fake(['api.fortnox.se/*' => Http::sequence()
            ->push(['ErrorInformation' => ['Error' => 1, 'Message' => 'Too many requests', 'Code' => 0]], 429)
            ->push(['Customers' => []])]);

        app(FortnoxClient::class)->get('customers');

        Sleep::assertSleptTimes(1);
    }

    public function test_fortnox_error_information_is_turned_into_a_readable_message(): void
    {
        $this->connected();
        Http::fake(['api.fortnox.se/*' => Http::response(['ErrorInformation' => [
            'error' => 1, 'message' => 'Kunden med kundnummer 999 hittades inte.', 'code' => 2000204,
        ]], 400)]);

        try {
            app(FortnoxClient::class)->post('orders', ['Order' => []]);
            $this->fail('expected exception');
        } catch (ErpException $e) {
            $this->assertStringContainsString('code 2000204', $e->getMessage());
            $this->assertStringContainsString('hittades inte', $e->getMessage());
            $this->assertFalse($e->retryable);
        }
    }

    // --- Adapter ------------------------------------------------------------

    private function pricedOrder(): OrderLog
    {
        $customer = B2bCustomer::factory()->create(['fortnox_customer_number' => '1042']);

        return OrderLog::factory()->for($customer, 'customer')->create([
            'woo_order_id' => 727,
            'status' => OrderLog::STATUS_PRICED,
            'currency' => 'SEK',
            'received_at' => '2026-10-31 23:30:00', // UTC → 1 Nov in Stockholm
            'woo_payload' => ['id' => 727, 'shipping' => ['company' => 'Lundberg Café & Deli AB', 'address_1' => 'Stortorget 4', 'postcode' => '222 23', 'city' => 'Lund']],
            'priced_lines' => [
                ['sku' => 'VC-KAF-4735', 'fortnox_article_number' => 'KAF4735', 'name' => 'Kaffe mörkrost 1 kg', 'quantity' => 24, 'unit_price' => '162.00', 'tier' => 'gold'],
                ['sku' => 'VC-TEA-1627', 'fortnox_article_number' => 'TEA1627', 'name' => 'Rooibos 250 g', 'quantity' => 12, 'unit_price' => '48.75', 'tier' => 'gold'],
            ],
        ]);
    }

    public function test_push_order_sends_fortnox_order_format_and_returns_the_document_number(): void
    {
        $this->connected();
        Http::fake(['api.fortnox.se/3/orders' => Http::response(['Order' => ['DocumentNumber' => '15']], 201)]);

        $number = app(FortnoxAdapter::class)->pushOrder($this->pricedOrder());

        $this->assertSame('15', $number);
        Http::assertSent(function (Request $r) {
            $o = $r['Order'];

            return $r->method() === 'POST'
                && $o['CustomerNumber'] === '1042'
                && $o['YourOrderNumber'] === '727'
                && $o['OrderDate'] === '2026-11-01'
                && $o['VATIncluded'] === false
                && $o['DeliveryCity'] === 'Lund'
                && str_contains($o['Remarks'], 'gold')
                && $o['OrderRows'] === [
                    ['ArticleNumber' => 'KAF4735', 'Description' => 'Kaffe mörkrost 1 kg', 'OrderedQuantity' => '24', 'DeliveredQuantity' => '24', 'Price' => '162.00'],
                    ['ArticleNumber' => 'TEA1627', 'Description' => 'Rooibos 250 g', 'OrderedQuantity' => '12', 'DeliveredQuantity' => '12', 'Price' => '48.75'],
                ];
        });
    }

    public function test_push_order_refuses_unlinked_customers_and_articles_without_calling_fortnox(): void
    {
        $this->connected();
        Http::fake();
        $order = $this->pricedOrder();

        $order->customer->update(['fortnox_customer_number' => null]);
        try {
            app(FortnoxAdapter::class)->pushOrder($order->fresh());
            $this->fail('expected exception');
        } catch (ErpException $e) {
            $this->assertStringContainsString('not linked to a Fortnox customer', $e->getMessage());
        }

        $order->customer->update(['fortnox_customer_number' => '1042']);
        $lines = $order->priced_lines;
        $lines[1]['fortnox_article_number'] = null;
        $order->update(['priced_lines' => $lines]);
        $this->expectExceptionMessage('SKU VC-TEA-1627 has no Fortnox article number');
        try {
            app(FortnoxAdapter::class)->pushOrder($order->fresh());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_stock_levels_use_disposable_quantity_and_skip_non_stock_articles(): void
    {
        $this->connected();
        Http::fake([
            'api.fortnox.se/3/articles/KAF4735' => Http::response(['Article' => ['StockGoods' => true, 'QuantityInStock' => 120, 'DisposableQuantity' => 96.0]]),
            'api.fortnox.se/3/articles/SERVICE1' => Http::response(['Article' => ['StockGoods' => false, 'QuantityInStock' => 0]]),
        ]);

        $this->assertSame(['KAF4735' => 96], app(FortnoxAdapter::class)->stockLevels(['KAF4735', 'SERVICE1']));
    }

    public function test_pull_customers_walks_all_pages(): void
    {
        $this->connected();
        Http::fake(['api.fortnox.se/3/customers*' => Http::sequence()
            ->push(['Customers' => [['CustomerNumber' => '1', 'Name' => 'A AB', 'Email' => 'a@a.se']], 'MetaInformation' => ['@TotalPages' => 2, '@CurrentPage' => 1]])
            ->push(['Customers' => [['CustomerNumber' => '2', 'Name' => 'B AB', 'OrganisationNumber' => '556677-8899']], 'MetaInformation' => ['@TotalPages' => 2, '@CurrentPage' => 2]])]);

        $customers = collect(app(FortnoxAdapter::class)->pullCustomers());

        $this->assertSame(['1', '2'], $customers->pluck('customerNumber')->all());
        $this->assertSame('556677-8899', $customers[1]->organisationNumber);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'page=2'));
    }

    // --- Pipeline -----------------------------------------------------------

    public function test_order_sync_job_pushes_to_fortnox_and_marks_the_order_synced(): void
    {
        $this->connected();
        Http::fake(['api.fortnox.se/3/orders' => Http::response(['Order' => ['DocumentNumber' => '15']], 201)]);
        $order = $this->pricedOrder();
        $job = $order->syncJobs()->create(['type' => SyncJob::TYPE_ORDER_PUSH, 'status' => SyncJob::STATUS_PENDING]);

        app()->call([new SyncOrderToErp($job->id), 'handle']);

        $order->refresh();
        $this->assertSame([OrderLog::STATUS_SYNCED, '15'], [$order->status, $order->erp_document_number]);
        $this->assertSame(SyncJob::STATUS_SUCCEEDED, $job->fresh()->status);

        app()->call([new SyncOrderToErp($job->id), 'handle']); // already done: no second POST
        Http::assertSentCount(1);
    }

    public function test_fortnox_outage_keeps_the_order_priced_and_schedules_a_retry(): void
    {
        $this->connected();
        Http::fake(['api.fortnox.se/*' => Http::response('Service Unavailable', 503)]);
        $order = $this->pricedOrder();
        $job = $order->syncJobs()->create(['type' => SyncJob::TYPE_ORDER_PUSH, 'status' => SyncJob::STATUS_PENDING]);

        app()->call([new SyncOrderToErp($job->id), 'handle']);

        $this->assertSame(OrderLog::STATUS_PRICED, $order->fresh()->status, 'still a valid sale, keeps reserving stock');
        $job->refresh();
        $this->assertSame(SyncJob::STATUS_FAILED, $job->status);
        $this->assertNotNull($job->next_retry_at);
        $this->assertStringContainsString('HTTP 503', $job->last_error);
    }

    public function test_inventory_sync_takes_warehouse_stock_from_fortnox(): void
    {
        $this->connected();
        config([
            'services.woocommerce.base_url' => 'https://shop.example.se',
            'services.woocommerce.consumer_key' => 'ck', 'services.woocommerce.consumer_secret' => 'cs',
        ]);
        Queue::fake();
        $sku = ProductSku::factory()->create(['sku' => 'VC-KAF-4735', 'fortnox_article_number' => 'KAF4735', 'woo_product_id' => 15]);
        InventoryCache::factory()->create(['product_sku_id' => $sku->id, 'warehouse_quantity' => 5, 'reserved_quantity' => 0, 'buffer_quantity' => 10]);
        Http::fake([
            'api.fortnox.se/3/articles/KAF4735' => Http::response(['Article' => ['StockGoods' => true, 'DisposableQuantity' => 96]]),
            'shop.example.se/*' => Http::response(['id' => 15, 'manage_stock' => true, 'stock_quantity' => 0]),
        ]);

        $id = app(InventorySyncRequester::class)->request($sku)['sync_job_id'];
        app()->call([new SyncInventory($id), 'handle']);

        $result = SyncJob::find($id)->payload['result'];
        $this->assertSame([96, 86, 'fortnox'], [$result['warehouse'], $result['available'], $result['erp']]);
        $this->assertNotNull($sku->inventory()->first()->erp_synced_at);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['stock_quantity'] === 86);
    }

    public function test_erp_driver_config_selects_the_adapter(): void
    {
        $this->assertInstanceOf(FortnoxAdapter::class, app(ErpAdapter::class));
        config(['erp.driver' => 'fake']);
        $this->assertSame('fake', app(ErpAdapter::class)->name());
    }
}
