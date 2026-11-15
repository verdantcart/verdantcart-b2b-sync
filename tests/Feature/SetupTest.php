<?php

namespace Tests\Feature;

use App\Erp\Fortnox\FortnoxClient;
use App\Jobs\SyncInventory;
use App\Models\ErpConnection;
use App\Models\InventoryCache;
use App\Models\ProductSku;
use App\Models\User;
use App\Services\WooCommerce\WooCommerceClient;
use App\Support\StockBuffer;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        app()->detectEnvironment(fn () => 'local'); // admin pages are open locally without a password
        $this->withoutMiddleware(PreventRequestForgery::class); // CSRF itself is tested in SyncRetryTest
        config([
            'app.url' => 'https://b2b-acme.fly.dev',
            'services.woocommerce.base_url' => 'https://shop.acme.test',
            'services.woocommerce.consumer_key' => 'ck_test',
            'services.woocommerce.consumer_secret' => 'cs_test',
            'services.woocommerce.webhook_secret' => 'whsec',
            'erp.driver' => 'fortnox',
            'erp.fortnox.client_id' => 'client-123',
            'erp.fortnox.client_secret' => 'secret-456',
            'erp.fortnox.redirect_uri' => 'https://b2b-acme.fly.dev/fortnox/callback',
        ]);
        $this->app->forgetInstance(WooCommerceClient::class);
        $this->app->forgetInstance(FortnoxClient::class);
    }

    private function stepState(string $key): string
    {
        return collect($this->get('/setup')->assertOk()->viewData('steps'))->firstWhere('key', $key)['state'];
    }

    private function fakeWoo(array $webhooks = []): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'shop.acme.test/wp-json/wc/v3/products*' => Http::response([], 200, ['X-WP-Total' => '42']),
            'shop.acme.test/wp-json/wc/v3/settings/general*' => Http::response([['id' => 'woocommerce_currency', 'value' => 'SEK']]),
            'shop.acme.test/wp-json/wc/v3/webhooks?*' => Http::response($webhooks),
            'shop.acme.test/wp-json/wc/v3/webhooks' => fn (Request $r) => Http::response(['id' => random_int(1, 999), 'status' => 'active'] + $r->data(), 201),
            'shop.acme.test/wp-json/wc/v3/webhooks/*' => fn (Request $r) => Http::response(['status' => 'active'] + $r->data()),
        ]);
    }

    private function fakeFortnox(array $missingLicence = []): void
    {
        Http::preventStrayRequests();
        $noLicence = Http::response(['ErrorInformation' => ['Error' => 1, 'Message' => 'Det finns ingen aktiv licens för önskat scope', 'Code' => 2001101]], 403);
        Http::fake([
            'api.fortnox.se/3/companyinformation' => Http::response(['CompanyInformation' => ['CompanyName' => 'Acme AB', 'OrganizationNumber' => '556000-0000']]),
            'api.fortnox.se/3/orders*' => in_array('orders', $missingLicence, true) ? $noLicence : Http::response(['Orders' => []]),
            'api.fortnox.se/3/customers*' => Http::response(['Customers' => []]),
            'api.fortnox.se/3/articles*' => Http::response(['Articles' => []]),
        ]);
    }

    private function connectFortnox(): void
    {
        ErpConnection::create([
            'driver' => 'fortnox', 'access_token' => 'access-1', 'refresh_token' => 'refresh-1',
            'expires_at' => now()->addHour(), 'connected_at' => now(),
        ]);
    }

    // --- Access ----------------------------------------------------------------------------

    public function test_setup_and_dashboard_need_the_admin_login_outside_local(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['horizon.basic_auth.user' => 'admin', 'horizon.basic_auth.password' => 's3cret']);

        $this->get('/setup')->assertUnauthorized();
        $this->get('/')->assertUnauthorized();
        $this->withBasicAuth('admin', 's3cret')->get('/setup')->assertOk();
        $this->withBasicAuth('admin', 's3cret')->get('/')->assertOk();
    }

    public function test_the_public_demo_opens_only_the_dashboard(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['horizon.basic_auth.password' => 's3cret', 'b2bsync.public_dashboard' => true]);

        $this->get('/')->assertOk()->assertDontSee('Continue setup');
        $this->get('/setup')->assertUnauthorized();
    }

    // --- The page --------------------------------------------------------------------------

    public function test_a_fresh_installation_shows_what_is_left_without_calling_any_api(): void
    {
        Http::preventStrayRequests();

        $response = $this->get('/setup')->assertOk()
            ->assertSee('0 of 5 steps done')
            ->assertSee('https://b2b-acme.fly.dev/webhooks/woocommerce')
            ->assertSee('https://b2b-acme.fly.dev/fortnox/callback');

        $this->assertSame(['todo', 'todo', 'todo', 'todo', 'todo'], collect($response->viewData('steps'))->pluck('state')->all());
        $this->get('/')->assertSee('Setup: 0 of 5 steps done')->assertSee('Continue setup');
    }

    public function test_missing_keys_are_explained(): void
    {
        config(['services.woocommerce.consumer_key' => '', 'erp.fortnox.client_id' => '']);

        $this->get('/setup')->assertSee('WOO_CONSUMER_KEY')->assertSee('FORTNOX_CLIENT_ID');
        $this->assertSame('problem', $this->stepState('woo'));
        $this->assertSame('problem', $this->stepState('fortnox'));
    }

    // --- WooCommerce -----------------------------------------------------------------------

    public function test_checking_woocommerce_remembers_the_store_and_which_webhooks_are_missing(): void
    {
        $this->fakeWoo(webhooks: [
            ['id' => 7, 'topic' => 'order.created', 'delivery_url' => 'https://b2b-acme.fly.dev/webhooks/woocommerce', 'status' => 'active'],
        ]);

        $this->post('/setup/woo/check')->assertRedirect(route('setup').'#woo');
        $this->followingRedirects()->post('/setup/woo/check')->assertSee('42 products, currency SEK');

        $this->assertSame('done', $this->stepState('woo'));
        $this->assertSame('todo', $this->stepState('webhooks'));
        $missing = collect($this->get('/setup')->viewData('steps'))->firstWhere('key', 'webhooks')['missing'];
        $this->assertSame(['product.created', 'product.updated'], $missing);
    }

    public function test_a_refused_woocommerce_key_is_explained(): void
    {
        Http::fake(['*' => Http::response(['code' => 'woocommerce_rest_cannot_view', 'message' => 'Sorry, you cannot list resources.'], 401)]);

        $this->followingRedirects()->post('/setup/woo/check')->assertSee('WooCommerce refused the API key');
        $this->assertSame('problem', $this->stepState('woo'));
    }

    public function test_an_unreachable_store_fails_fast_with_a_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->followingRedirects()->post('/setup/woo/check')->assertSee('Could not reach WooCommerce');

        Sleep::assertNeverSlept(); // no retry back-off while someone waits on the page
        $this->assertSame('problem', $this->stepState('woo'));
    }

    public function test_registering_webhooks_creates_the_missing_ones_and_completes_the_step(): void
    {
        $this->fakeWoo();
        $this->post('/setup/woo/check');

        $this->followingRedirects()->post('/setup/woo/webhooks')->assertSee('3 webhooks active (3 new)');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/webhooks')
            && $r['topic'] === 'order.created' && $r['secret'] === 'whsec'
            && $r['delivery_url'] === 'https://b2b-acme.fly.dev/webhooks/woocommerce');
        $this->assertSame('done', $this->stepState('webhooks'));
    }

    public function test_webhooks_are_not_registered_without_a_signing_secret(): void
    {
        config(['services.woocommerce.webhook_secret' => '']);
        Http::preventStrayRequests();

        $this->followingRedirects()->post('/setup/woo/webhooks')->assertSee('WOO_WEBHOOK_SECRET is empty');
    }

    // --- Fortnox ---------------------------------------------------------------------------

    public function test_licences_can_be_checked_once_logged_in(): void
    {
        $this->get('/setup')->assertSee('Log in to Fortnox')->assertDontSee('Check licences');

        $this->connectFortnox();
        $this->fakeFortnox();

        $this->followingRedirects()->post('/setup/fortnox/check')
            ->assertSee('Fortnox works for Acme AB')
            ->assertSee('Acme AB (556000-0000)');
        $this->assertSame('done', $this->stepState('fortnox'));
    }

    public function test_a_missing_fortnox_licence_is_named(): void
    {
        $this->connectFortnox();
        $this->fakeFortnox(missingLicence: ['orders']);

        $this->followingRedirects()->post('/setup/fortnox/check')
            ->assertSee('This Fortnox company is missing: Offert &amp; Order.', false);
        $this->assertSame('problem', $this->stepState('fortnox'));
    }

    public function test_logging_in_again_forgets_the_previous_company_check(): void
    {
        $this->connectFortnox();
        $this->fakeFortnox();
        $this->post('/setup/fortnox/check');
        $this->assertSame('done', $this->stepState('fortnox'));

        Http::fake(['apps.fortnox.se/oauth-v1/token' => Http::response([
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'scope' => 'order',
        ])]);
        $this->withSession(['fortnox_oauth_state' => 'abc'])->get('/fortnox/callback?code=c&state=abc')
            ->assertRedirect(route('setup').'#fortnox');

        $this->assertSame('todo', $this->stepState('fortnox'), 'another company may have been chosen');
    }

    // --- Stock buffer ----------------------------------------------------------------------

    public function test_the_buffer_is_used_for_new_products(): void
    {
        config(['b2bsync.inventory.default_buffer' => 0]);
        $existing = InventoryCache::factory()->create(['buffer_quantity' => 1]);

        $this->post('/setup/buffer', ['buffer' => 3])->assertRedirect(route('setup').'#buffer');

        $this->assertSame(3, StockBuffer::default());
        $this->assertSame(1, $existing->fresh()->buffer_quantity, 'products that have a buffer keep it');
        $this->assertSame('done', $this->stepState('buffer'));
    }

    public function test_the_buffer_can_be_applied_to_every_product_and_pushed_to_woocommerce(): void
    {
        Queue::fake();
        $skus = ProductSku::factory()->count(2)->create(['is_active' => true]);
        $skus->each(fn ($sku) => InventoryCache::factory()->create(['product_sku_id' => $sku->id, 'buffer_quantity' => 1]));

        $this->followingRedirects()->post('/setup/buffer', ['buffer' => 5, 'apply_to_all' => '1'])
            ->assertSee('2 stock updates queued');

        $this->assertSame([5, 5], InventoryCache::pluck('buffer_quantity')->all());
        Queue::assertPushed(SyncInventory::class, 2);
    }

    public function test_the_buffer_must_be_a_sensible_number(): void
    {
        $this->post('/setup/buffer', ['buffer' => -1])->assertSessionHasErrors('buffer');
        $this->post('/setup/buffer', ['buffer' => 'many'])->assertSessionHasErrors('buffer');
        $this->assertFalse(StockBuffer::isSetInApp());
    }

    // --- Storefront token ------------------------------------------------------------------

    public function test_the_plugin_token_can_only_read_prices_and_is_shown_once(): void
    {
        $response = $this->followingRedirects()->post('/setup/token')->assertSee('cannot be shown again');

        $token = PersonalAccessToken::sole();
        $this->assertSame(['prices:read'], $token->abilities);
        $this->assertSame(User::API_SERVICE_EMAIL, $token->tokenable->email);
        $this->assertStringStartsWith($token->id.'|', $this->plainToken($response));

        $this->get('/setup')->assertDontSee('cannot be shown again');
        $this->assertSame('done', $this->stepState('token'));
    }

    private function plainToken($response): string
    {
        preg_match('/<code class="token">([^<]+)<\/code>/', $response->getContent(), $m);

        return $m[1] ?? '';
    }
}
