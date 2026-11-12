<?php

namespace Tests\Feature;

use App\Erp\Fortnox\FortnoxClient;
use App\Jobs\SyncOrderToErp;
use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use App\Models\ErpConnection;
use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\User;
use App\Services\Pricing\PriceResolver;
use App\Services\WooCommerce\Webhooks\OrderCreatedHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The whole chain, as the B2B Prices plugin drives it:
 *   storefront price API  →  WooCommerce charges that price  →  order webhook  →  Fortnox order
 * The price must be the same at every step.
 */
class EndToEndPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ProductSku::factory()->create(['sku' => 'VC-KAF-4735', 'base_price' => '180.00', 'fortnox_article_number' => 'KAF4735']);
        ProductSku::factory()->create(['sku' => 'VC-TEA-1627', 'base_price' => '54.17', 'fortnox_article_number' => 'TEA1627', 'woo_product_id' => 17]);

        $customer = B2bCustomer::factory()->create(['woo_customer_id' => 3, 'fortnox_customer_number' => '1042', 'is_active' => true]);
        $customer->priceTiers()->attach(B2bPriceTier::factory()->create(['slug' => 'gold', 'discount_percent' => 10, 'priority' => 20]));
    }

    /** What the plugin asks for and what the shop then shows the customer. */
    private function shopPrices(): array
    {
        Sanctum::actingAs(User::factory()->create(), ['prices:read']);
        $prices = $this->getJson('/api/woo-customers/3/prices?skus=VC-KAF-4735,VC-TEA-1627')->assertOk()->json('data.prices');
        $this->app['auth']->forgetGuards();

        return array_map(fn (array $p) => $p['unit_price'], $prices);
    }

    /** A WooCommerce order where each line was charged at the given unit price. */
    private function wooOrder(int $id, array $quantities, array $unitPrices): array
    {
        $order = json_decode(file_get_contents(base_path('tests/Fixtures/woocommerce/order-created.json')), true);
        $order['id'] = $id;
        $order['number'] = (string) $id;

        foreach ($order['line_items'] as $i => &$line) {
            $quantity = $quantities[$line['sku']];
            $charged = PriceResolver::fromCents(PriceResolver::toCents($unitPrices[$line['sku']]) * $quantity);
            $line = array_merge($line, ['id' => $id * 10 + $i, 'quantity' => $quantity, 'price' => (float) $unitPrices[$line['sku']], 'subtotal' => $charged, 'total' => $charged]);
        }

        return $order;
    }

    private function receiveAndProcess(array $wooOrder): OrderLog
    {
        Queue::fake();
        $result = app(OrderCreatedHandler::class)->handle($wooOrder);
        app()->call([new SyncOrderToErp($result['sync_job_id']), 'handle']);

        return OrderLog::findOrFail($result['order_log_id']);
    }

    public function test_three_orders_at_shop_prices_reach_the_erp_with_no_price_difference(): void
    {
        $shop = $this->shopPrices();
        $this->assertSame(['VC-KAF-4735' => '162.00', 'VC-TEA-1627' => '48.75'], $shop);

        foreach ([[901, 24, 12], [902, 1, 1], [903, 7, 33]] as [$id, $coffee, $tea]) {
            $order = $this->receiveAndProcess($this->wooOrder($id, ['VC-KAF-4735' => $coffee, 'VC-TEA-1627' => $tea], $shop));

            $this->assertSame(OrderLog::STATUS_SYNCED, $order->status, "order {$id}");
            $this->assertSame('0.00', $order->price_difference, "order {$id} has a price difference");
            foreach ($order->priced_lines as $line) {
                $this->assertSame($shop[$line['sku']], $line['unit_price'], "order {$id}, {$line['sku']}");
                $this->assertSame($line['woo_line_total'], $line['line_total'], "order {$id}, {$line['sku']}");
            }
        }

        $this->withoutMiddleware()->get('/')->assertOk()->assertSee('None. Every order was charged in the shop at the same price');
    }

    public function test_the_fortnox_order_carries_the_price_the_shop_showed(): void
    {
        Sleep::fake();
        config(['erp.driver' => 'fortnox', 'erp.fortnox.client_id' => 'client-123', 'erp.fortnox.client_secret' => 'secret-456']);
        $this->app->forgetInstance(FortnoxClient::class);
        ErpConnection::create(['driver' => 'fortnox', 'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_at' => now()->addHour(), 'connected_at' => now()]);
        Http::fake(['api.fortnox.se/3/orders' => Http::response(['Order' => ['DocumentNumber' => '77']], 201)]);

        $shop = $this->shopPrices();
        $order = $this->receiveAndProcess($this->wooOrder(904, ['VC-KAF-4735' => 5, 'VC-TEA-1627' => 3], $shop));

        $this->assertSame([OrderLog::STATUS_SYNCED, '77', '0.00'], [$order->status, $order->erp_document_number, $order->price_difference]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '3/orders')
            && collect($r['Order']['OrderRows'])->pluck('Price', 'ArticleNumber')->all() === ['KAF4735' => $shop['VC-KAF-4735'], 'TEA1627' => $shop['VC-TEA-1627']]);
    }

    public function test_an_order_charged_at_list_price_is_flagged_and_still_invoiced_at_the_b2b_price(): void
    {
        Log::spy();

        // B2B Sync was unreachable at checkout, so the plugin let the shop charge its own price.
        $order = $this->receiveAndProcess($this->wooOrder(905, ['VC-KAF-4735' => 10, 'VC-TEA-1627' => 1], ['VC-KAF-4735' => '180.00', 'VC-TEA-1627' => '54.17']));

        $this->assertSame('-185.42', $order->price_difference); // (1620.00 − 1800.00) + (48.75 − 54.17)
        $this->assertSame('162.00', $order->priced_lines[0]['unit_price'], 'Fortnox gets the agreed B2B price');
        $this->assertTrue($order->hasPriceDifference());
        $this->assertSame(1, OrderLog::withPriceDifference()->count());

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'Shop charged a different price than the B2B price'
            && $context['woo_order_id'] === 905
            && $context['lines'] === ['VC-KAF-4735: shop 1800.00, B2B 1620.00', 'VC-TEA-1627: shop 54.17, B2B 48.75']);

        $this->withoutMiddleware()->get('/')->assertOk()
            ->assertSee('Price differences')
            ->assertSee('#905')
            ->assertSee('-185.42');
    }
}
