<?php

namespace Tests\Feature;

use App\Erp\Fortnox\FortnoxClient;
use App\Jobs\SyncInventory;
use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Day 10: a brand-new installation for an existing shop, taken from an empty database to the
 * first order in Fortnox using only the pages an admin clicks through, then the shop's own
 * calls (price API and order webhook). WooCommerce and Fortnox are faked at the HTTP level.
 */
class FromZeroTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec-from-zero';

    /** Every Fortnox order the app created. */
    private array $fortnoxOrders = [];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        app()->detectEnvironment(fn () => 'local');
        $this->withoutMiddleware(PreventRequestForgery::class);
        Queue::fake([SyncInventory::class]); // stock pushes are covered by InventorySyncTest
        config([
            'app.url' => 'https://b2b-acme.fly.dev',
            'services.woocommerce.base_url' => 'https://shop.acme.test',
            'services.woocommerce.consumer_key' => 'ck_live',
            'services.woocommerce.consumer_secret' => 'cs_live',
            'services.woocommerce.webhook_secret' => self::SECRET,
            'erp.driver' => 'fortnox',
            'erp.fortnox.client_id' => 'client-123',
            'erp.fortnox.client_secret' => 'secret-456',
            'erp.fortnox.redirect_uri' => 'https://b2b-acme.fly.dev/fortnox/callback',
        ]);
        $this->app->forgetInstance(WooCommerceClient::class);
        $this->app->forgetInstance(FortnoxClient::class);
        $this->fakeTheWorld();
    }

    private function fakeTheWorld(): void
    {
        Http::preventStrayRequests();
        $woo = 'shop.acme.test/wp-json/wc/v3/';
        $fortnox = 'api.fortnox.se/3/';
        Http::fake([
            // WooCommerce: 2 products, one shop customer, no webhooks yet.
            $woo.'products/*/variations*' => Http::response([]),
            $woo.'products*' => Http::response([
                ['id' => 10, 'type' => 'simple', 'sku' => 'TEA-100', 'name' => 'Green tea', 'regular_price' => '200.00', 'status' => 'publish'],
                ['id' => 11, 'type' => 'simple', 'sku' => 'VC-KAF-4735', 'name' => 'Coffee', 'regular_price' => '180.00', 'status' => 'publish'],
            ], 200, ['X-WP-Total' => '2', 'X-WP-TotalPages' => '1']),
            $woo.'settings/general*' => Http::response([['id' => 'woocommerce_currency', 'value' => 'SEK'], ['id' => 'woocommerce_calc_taxes', 'value' => 'yes']]),
            $woo.'settings/tax*' => Http::response([['id' => 'woocommerce_prices_include_tax', 'value' => 'no']]),
            $woo.'webhooks?*' => Http::response([]),
            $woo.'webhooks' => fn (Request $r) => Http::response(['id' => random_int(1, 999), 'status' => 'active'] + $r->data(), 201),
            $woo.'customers*' => Http::response([['id' => 3, 'first_name' => 'Sara', 'last_name' => 'Lind', 'email' => 'sara@nordictea.se']]),

            // Fortnox: login, licences, articles, customers, orders.
            'apps.fortnox.se/oauth-v1/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'scope' => 'order customer article companyinformation']),
            $fortnox.'companyinformation' => Http::response(['CompanyInformation' => ['CompanyName' => 'Acme AB', 'OrganizationNumber' => '556000-0000']]),
            $fortnox.'articles*' => Http::response(['Articles' => [['ArticleNumber' => 'TEA-100', 'Description' => 'Green tea'], ['ArticleNumber' => 'KAF4735', 'Description' => 'Coffee']], 'MetaInformation' => ['@TotalPages' => 1]]),
            $fortnox.'customers*' => Http::response(['Customers' => [
                ['CustomerNumber' => '1042', 'Name' => 'Nordic Tea AB', 'OrganisationNumber' => '556677-8899', 'Email' => 'Sara@NordicTea.se', 'CountryCode' => 'SE', 'Currency' => 'SEK', 'Active' => true],
                ['CustomerNumber' => '1043', 'Name' => 'No Mail AB', 'Active' => true],
            ], 'MetaInformation' => ['@TotalPages' => 1]]),
            $fortnox.'orders?*' => Http::response(['Orders' => [], 'MetaInformation' => ['@TotalPages' => 1]]),
            $fortnox.'orders' => function (Request $r) {
                $this->fortnoxOrders[] = $r['Order'];

                return Http::response(['Order' => ['DocumentNumber' => (string) (500 + count($this->fortnoxOrders))]], 201);
            },
        ]);
    }

    private function step(string $key): string
    {
        return collect($this->get('/setup')->assertOk()->viewData('steps'))->firstWhere('key', $key)['state'];
    }

    public function test_a_new_installation_reaches_its_first_fortnox_order_through_the_pages(): void
    {
        $this->get('/setup')->assertSee('0 of 8 steps done');

        // 1–2. WooCommerce and webhooks.
        $this->post('/setup/woo/check');
        $this->post('/setup/woo/webhooks');
        $this->assertSame(['done', 'done'], [$this->step('woo'), $this->step('webhooks')]);

        // 3. The customer logs in to Fortnox and the licences are checked.
        $this->get('/fortnox/connect')->assertRedirect();
        $this->get('/fortnox/callback?code=abc&state='.session('fortnox_oauth_state'))->assertRedirect(route('setup').'#fortnox');
        $this->post('/setup/fortnox/check');
        $this->assertSame('done', $this->step('fortnox'));

        // 4. Buffer.
        $this->post('/setup/buffer', ['buffer' => 2]);

        // 5. Products: TEA-100 links by itself, the coffee needs the suggested article confirmed.
        $this->post('/setup/products/import');
        $this->assertSame('problem', $this->step('products'));
        $coffee = ProductSku::where('sku', 'VC-KAF-4735')->sole();
        $this->get('/setup')->assertSee('value="KAF4735"', false);
        $this->post('/setup/products/link', ['articles' => [$coffee->id => 'KAF4735']]);
        $this->assertSame('done', $this->step('products'));

        // 6. Customers come from Fortnox; the one without e-mail is skipped and reported.
        $this->post('/setup/customers/import');
        $this->get('/setup')->assertSee('1 new, 0 linked, 0 updated')->assertSee('1 skipped (no e-mail)');
        $customer = B2bCustomer::sole();
        $this->assertSame(['1042', 'sara@nordictea.se', null], [$customer->fortnox_customer_number, $customer->email, $customer->woo_customer_id]);

        // 7. A Gold tier, and the customer linked to their shop login and put in it.
        $this->post('/tiers', ['name' => 'Guld', 'discount_percent' => '10', 'priority' => 20, 'is_active' => '1']);
        $this->post("/customers/{$customer->id}/find-in-woo")->assertSessionHas('status');
        $this->assertSame(3, $customer->fresh()->woo_customer_id, 'linked without a separate Save');
        $this->post("/customers/{$customer->id}/tiers", ['tier_id' => B2bPriceTier::sole()->id]);
        $this->assertSame('done', $this->step('tiers'));

        // 8. Token for the B2B Prices plugin.
        $this->followingRedirects()->post('/setup/token')->assertSee('cannot be shown again');
        $token = (string) str(session('new_token') ?? '')->whenEmpty(fn () => $this->lastToken());
        $this->get('/setup')->assertSee('8 of 8 steps done')->assertSee('All set');

        // The shop: the plugin asks for Sara's prices, the shop charges them, the order comes in.
        $prices = $this->withToken($token)->getJson('/api/woo-customers/3/prices?skus=TEA-100,VC-KAF-4735')->assertOk()->json('data.prices');
        $this->assertSame(['180.00', '162.00'], [$prices['TEA-100']['unit_price'], $prices['VC-KAF-4735']['unit_price']]);
        $this->app['auth']->forgetGuards();

        $this->deliverOrder(1001, customerId: 3, lines: [['TEA-100', 10, 2, '180.00'], ['VC-KAF-4735', 11, 1, '162.00']])->assertOk();

        // Fortnox got the order on Sara's customer, at the shop's prices, with the right articles.
        $order = OrderLog::sole();
        $this->assertSame([OrderLog::STATUS_SYNCED, '501', '0.00'], [$order->status, $order->erp_document_number, $order->price_difference]);
        $this->assertCount(1, $this->fortnoxOrders);
        $this->assertSame('1042', $this->fortnoxOrders[0]['CustomerNumber']);
        $this->assertSame(['TEA-100' => '180.00', 'KAF4735' => '162.00'], collect($this->fortnoxOrders[0]['OrderRows'])->pluck('Price', 'ArticleNumber')->all());
        $this->assertStringContainsString('Price tier: guld', $this->fortnoxOrders[0]['Remarks']);

        $this->get('/')->assertDontSee('Continue setup');
        $this->assertSame(['synced' => 1], collect($this->get('/')->viewData('ordersByStatus'))->all());
        $this->get("/customers/{$customer->id}")->assertSee('#1001')->assertSee('Guld · 10 % off');
    }

    /** Plain token from the page, when the session flash is already gone. */
    private function lastToken(): string
    {
        $this->post('/setup/token');

        return (string) session('new_token');
    }

    /** @param list<array{0: string, 1: int, 2: int, 3: string}> $lines sku, product id, quantity, unit price */
    private function deliverOrder(int $id, int $customerId, array $lines)
    {
        $order = json_decode(file_get_contents(base_path('tests/Fixtures/woocommerce/order-created.json')), true);
        $order['id'] = $id;
        $order['number'] = (string) $id;
        $order['customer_id'] = $customerId;
        $order['billing']['email'] = 'sara@nordictea.se';
        $order['line_items'] = array_map(fn (array $l, int $i) => [
            'id' => $id * 10 + $i, 'name' => $l[0], 'product_id' => $l[1], 'variation_id' => 0, 'sku' => $l[0], 'quantity' => $l[2],
            'price' => (float) $l[3], 'subtotal' => number_format($l[2] * (float) $l[3], 2, '.', ''), 'total' => number_format($l[2] * (float) $l[3], 2, '.', ''),
        ], $lines, array_keys($lines));

        $body = json_encode($order);
        $headers = [
            'Content-Type' => 'application/json',
            'X-WC-Webhook-Topic' => 'order.created',
            'X-WC-Webhook-Resource' => 'order',
            'X-WC-Webhook-Event' => 'created',
            'X-WC-Webhook-Delivery-ID' => (string) $id,
            'X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $body, self::SECRET, true)),
        ];

        return $this->call('POST', '/webhooks/woocommerce', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }
}
