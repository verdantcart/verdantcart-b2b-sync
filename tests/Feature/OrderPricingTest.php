<?php

namespace Tests\Feature;

use App\Jobs\SyncOrderToErp;
use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\SyncJob;
use App\Services\WooCommerce\Webhooks\OrderCreatedHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderPricingTest extends TestCase
{
    use RefreshDatabase;

    private B2bCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Catalogue matching the fixture order: 24 × VC-KAF-4735, 12 × VC-TEA-1627
        ProductSku::factory()->create(['sku' => 'VC-KAF-4735', 'base_price' => '180.00', 'fortnox_article_number' => 'KAF4735']);
        ProductSku::factory()->create(['sku' => 'VC-TEA-1627', 'base_price' => '54.17', 'woo_product_id' => 17, 'woo_variation_id' => null]);

        $this->customer = B2bCustomer::factory()->create(['woo_customer_id' => 3, 'email' => 'inkop@mraz.se', 'is_active' => true]);
    }

    private function fixture(array $overrides = []): array
    {
        $order = json_decode(file_get_contents(base_path('tests/Fixtures/woocommerce/order-created.json')), true);

        return array_replace_recursive($order, $overrides);
    }

    /** Receive the order like the webhook does, then run the queued job like a worker. */
    private function receiveAndProcess(array $order): array
    {
        Queue::fake();
        $result = app(OrderCreatedHandler::class)->handle($order);
        Queue::assertPushed(SyncOrderToErp::class);

        app()->call([new SyncOrderToErp($result['sync_job_id']), 'handle']);

        return [OrderLog::find($result['order_log_id']), SyncJob::find($result['sync_job_id'])];
    }

    private function tier(string $slug, string $discount, int $priority = 10, array $pivot = []): B2bPriceTier
    {
        $tier = B2bPriceTier::factory()->create(['slug' => $slug, 'discount_percent' => $discount, 'priority' => $priority]);
        $this->customer->priceTiers()->attach($tier, $pivot);

        return $tier;
    }

    public function test_order_is_priced_with_the_customers_tier(): void
    {
        $this->tier('gold', '10.00');

        [$order, $job] = $this->receiveAndProcess($this->fixture());

        $this->assertSame(OrderLog::STATUS_SYNCED, $order->status); // priced, then pushed to the (fake) ERP
        $this->assertSame('FAKE-727', $order->erp_document_number);
        [$kaffe, $te] = $order->priced_lines;

        $this->assertSame(['VC-KAF-4735', 24, '180.00', 'gold', '162.00', '3888.00'],
            [$kaffe['sku'], $kaffe['quantity'], $kaffe['base_price'], $kaffe['tier'], $kaffe['unit_price'], $kaffe['line_total']]);
        $this->assertSame('KAF4735', $kaffe['fortnox_article_number']);
        $this->assertSame('4320.00', $kaffe['woo_line_total']);   // Woo charged list price…
        $this->assertSame('-432.00', $kaffe['difference']);        // …B2B price is 10 % lower

        $this->assertSame('48.75', $te['unit_price']);            // 54.17 × 0.9 = 48.753
        $this->assertSame('585.00', $te['line_total']);
        $this->assertSame('4473.00', $order->subtotal);           // 3888.00 + 585.00, exact

        $this->assertSame(SyncJob::STATUS_SUCCEEDED, $job->status);
        $this->assertSame('FAKE-727', $job->payload['erp_document_number']);
        $this->assertSame(1, $job->attempts);
    }

    public function test_tier_is_the_one_valid_when_the_order_was_placed(): void
    {
        // Campaign ran in October; the fixture order is from 31 Oct, processed "now" (later).
        $this->tier('q4-campaign', '12.00', 50, ['valid_from' => '2026-10-01', 'valid_until' => '2026-11-01']);
        $this->tier('silver', '5.00', 10);
        $this->travelTo('2026-11-20 12:00:00');

        [$order] = $this->receiveAndProcess($this->fixture());

        $this->assertSame('q4-campaign', $order->priced_lines[0]['tier']);
        $this->assertSame('158.40', $order->priced_lines[0]['unit_price']);
    }

    public function test_unknown_or_inactive_customer_pays_list_price(): void
    {
        [$guest] = $this->receiveAndProcess($this->fixture(['id' => 801, 'customer_id' => 0, 'billing' => ['email' => 'new@buyer.se']]));
        $this->assertNull($guest->b2b_customer_id);
        $this->assertSame('180.00', $guest->priced_lines[0]['unit_price']);
        $this->assertNull($guest->priced_lines[0]['tier']);

        $this->tier('gold', '10.00');
        $this->customer->update(['is_active' => false]);
        [$inactive] = $this->receiveAndProcess($this->fixture(['id' => 802]));
        $this->assertSame('180.00', $inactive->priced_lines[0]['unit_price']);
    }

    public function test_line_is_matched_on_woo_product_id_when_the_sku_text_changed(): void
    {
        $order = $this->fixture();
        $order['line_items'][1]['sku'] = 'RENAMED-IN-WOO';

        [$priced] = $this->receiveAndProcess($order);

        $this->assertSame(OrderLog::STATUS_SYNCED, $priced->status);
        $this->assertSame('VC-TEA-1627', $priced->priced_lines[1]['sku']);
    }

    public function test_unknown_sku_fails_the_order_with_a_clear_reason_and_is_not_retried(): void
    {
        $order = $this->fixture();
        $order['line_items'][1]['sku'] = 'VC-NOPE-0001';
        $order['line_items'][1]['product_id'] = 999;

        [$failed, $job] = $this->receiveAndProcess($order);

        $this->assertSame(OrderLog::STATUS_FAILED, $failed->status);
        $this->assertNull($failed->priced_lines);
        $this->assertStringContainsString('Unknown SKU: VC-NOPE-0001', $failed->last_error);
        $this->assertSame(SyncJob::STATUS_FAILED, $job->status);
    }

    public function test_running_the_job_twice_does_not_reprice(): void
    {
        $gold = $this->tier('gold', '10.00');
        [$order, $job] = $this->receiveAndProcess($this->fixture());
        $first = $order->priced_lines;

        $gold->update(['discount_percent' => '50.00']); // would change the price if it re-priced
        app()->call([new SyncOrderToErp($job->id), 'handle']);

        $this->assertSame($first, $order->fresh()->priced_lines);
        $this->assertSame(1, $job->fresh()->attempts, 'a succeeded job is not run again');
    }

    public function test_job_ignores_rows_it_should_not_touch(): void
    {
        [, $job] = $this->receiveAndProcess($this->fixture());
        $job->update(['status' => SyncJob::STATUS_RUNNING]); // another worker has it

        app()->call([new SyncOrderToErp($job->id), 'handle']);
        app()->call([new SyncOrderToErp(999999), 'handle']); // deleted row

        $this->assertSame(SyncJob::STATUS_RUNNING, $job->fresh()->status);
        $this->assertSame(1, $job->fresh()->attempts);
    }

    public function test_unexpected_crash_is_recorded_on_the_sync_job(): void
    {
        [, $job] = $this->receiveAndProcess($this->fixture());
        $job->update(['status' => SyncJob::STATUS_RUNNING]);

        (new SyncOrderToErp($job->id))->failed(new \RuntimeException('DB went away'));

        $this->assertSame(SyncJob::STATUS_FAILED, $job->fresh()->status);
        $this->assertStringContainsString('DB went away', $job->fresh()->last_error);
    }

    public function test_dispatch_pending_requeues_waiting_work_and_can_retry_failed_orders(): void
    {
        $order = $this->fixture();
        $order['line_items'][1]['sku'] = 'VC-NOPE-0001';
        $order['line_items'][1]['product_id'] = 999;
        [$failed, $job] = $this->receiveAndProcess($order);

        // Fix the catalogue, then retry.
        ProductSku::factory()->create(['sku' => 'VC-NOPE-0001', 'base_price' => '10.00']);
        Queue::fake();
        $this->artisan('sync:dispatch-pending', ['--include-failed' => true])
            ->expectsOutputToContain('Queued 1 order')
            ->assertSuccessful();
        Queue::assertPushed(SyncOrderToErp::class, fn ($j) => $j->syncJobId === $job->id);

        app()->call([new SyncOrderToErp($job->id), 'handle']);
        $this->assertSame(OrderLog::STATUS_SYNCED, $failed->fresh()->status);
    }
}
