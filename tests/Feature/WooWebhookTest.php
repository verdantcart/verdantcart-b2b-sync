<?php

namespace Tests\Feature;

use App\Jobs\SyncOrderToErp;
use App\Models\B2bCustomer;
use App\Models\OrderLog;
use App\Models\SyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WooWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.woocommerce.webhook_secret' => self::SECRET]);
        Queue::fake();
    }

    private function order(array $overrides = []): array
    {
        $order = json_decode(file_get_contents(base_path('tests/Fixtures/woocommerce/order-created.json')), true);

        return array_replace_recursive($order, $overrides);
    }

    /** Deliver a webhook exactly like WooCommerce does: raw JSON body + headers. */
    private function deliver(array $payload, string $topic = 'order.created', ?string $secret = self::SECRET): TestResponse
    {
        $body = json_encode($payload);
        $headers = [
            'Content-Type' => 'application/json',
            'X-WC-Webhook-Source' => 'http://woo-b2b.test/',
            'X-WC-Webhook-Topic' => $topic,
            'X-WC-Webhook-Resource' => explode('.', $topic)[0],
            'X-WC-Webhook-Event' => explode('.', $topic)[1] ?? '',
            'X-WC-Webhook-ID' => '1',
            'X-WC-Webhook-Delivery-ID' => (string) random_int(1, 99999),
        ];
        if ($secret !== null) {
            $headers['X-WC-Webhook-Signature'] = base64_encode(hash_hmac('sha256', $body, $secret, true));
        }

        return $this->call('POST', '/webhooks/woocommerce', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    public function test_signed_order_is_recorded_matched_to_customer_and_queued(): void
    {
        $customer = B2bCustomer::factory()->create(['woo_customer_id' => 3, 'email' => 'inkop@mraz.se']);

        $this->deliver($this->order())->assertOk()->assertJson(['status' => 'queued']);

        $order = OrderLog::sole();
        $this->assertSame(727, $order->woo_order_id);
        $this->assertSame($customer->id, $order->b2b_customer_id);
        $this->assertSame(OrderLog::STATUS_RECEIVED, $order->status);
        $this->assertSame('4970.00', $order->subtotal);
        $this->assertSame('6212.50', $order->total);
        $this->assertSame('VC-KAF-4735', $order->woo_payload['line_items'][0]['sku']);
        $this->assertSame('2026-10-31 15:12:05', $order->received_at->utc()->toDateTimeString());

        $job = SyncJob::sole();
        $this->assertSame(SyncJob::TYPE_ORDER_PUSH, $job->type);
        $this->assertSame(SyncJob::STATUS_PENDING, $job->status);
        $this->assertTrue($job->syncable->is($order));

        Queue::assertPushedOn('orders', SyncOrderToErp::class, fn (SyncOrderToErp $j) => $j->syncJobId === $job->id);
    }

    public function test_guest_checkout_is_matched_on_billing_email_case_insensitively(): void
    {
        $customer = B2bCustomer::factory()->create(['woo_customer_id' => 999, 'email' => 'inkop@mraz.se']);

        $this->deliver($this->order(['customer_id' => 0, 'billing' => ['email' => 'INKOP@Mraz.se']]))->assertOk();

        $this->assertSame($customer->id, OrderLog::sole()->b2b_customer_id);
    }

    public function test_unknown_customer_is_still_recorded(): void
    {
        $this->deliver($this->order(['customer_id' => 0, 'billing' => ['email' => 'someone@else.se']]))->assertOk();

        $this->assertNull(OrderLog::sole()->b2b_customer_id);
    }

    public function test_invalid_signature_is_rejected_and_nothing_is_stored(): void
    {
        $this->deliver($this->order(), secret: 'wrong-secret')->assertUnauthorized();

        $this->assertSame(0, OrderLog::count());
        Queue::assertNothingPushed();
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->deliver($this->order(), secret: null)->assertUnauthorized();

        $this->assertSame(0, OrderLog::count());
    }

    public function test_tampered_body_is_rejected(): void
    {
        $body = json_encode($this->order());
        $signature = base64_encode(hash_hmac('sha256', $body, self::SECRET, true));
        $tampered = str_replace('"6212.50"', '"1.00"', $body);

        $this->call('POST', '/webhooks/woocommerce', [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'X-WC-Webhook-Topic' => 'order.created',
            'X-WC-Webhook-Signature' => $signature,
        ]), $tampered)->assertUnauthorized();
    }

    public function test_duplicate_delivery_creates_one_order_and_one_queued_job(): void
    {
        $this->deliver($this->order())->assertOk()->assertJson(['status' => 'queued']);
        $this->deliver($this->order())->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, OrderLog::count());
        $this->assertSame(1, SyncJob::count());
        Queue::assertPushed(SyncOrderToErp::class, 1);
    }

    public function test_woocommerce_ping_on_webhook_creation_is_answered_without_side_effects(): void
    {
        $this->call('POST', '/webhooks/woocommerce', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], 'webhook_id=12')->assertOk()->assertJson(['status' => 'pong']);

        $this->assertSame(0, OrderLog::count());
    }

    public function test_unhandled_topics_are_acknowledged_but_ignored(): void
    {
        $this->deliver(['id' => 5, 'name' => 'Coupon'], 'coupon.created')
            ->assertStatus(202)
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(0, OrderLog::count());
    }

    public function test_missing_secret_configuration_fails_loudly(): void
    {
        config(['services.woocommerce.webhook_secret' => null]);

        $this->deliver($this->order())->assertServerError();
        $this->assertSame(0, OrderLog::count());
    }

    public function test_order_is_kept_as_pending_when_the_queue_is_down(): void
    {
        Bus::shouldReceive('dispatch')->once()
            ->andThrow(new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

        $this->deliver($this->order())->assertOk()->assertJson(['status' => 'queued']);

        $job = SyncJob::sole();
        $this->assertSame(SyncJob::STATUS_PENDING, $job->status);
        $this->assertStringContainsString('Queue dispatch failed', $job->last_error);
        $this->assertNotNull($job->next_retry_at);
    }

    public function test_webhook_route_does_not_start_a_session(): void
    {
        $this->deliver($this->order())->assertOk()->assertCookieMissing(config('session.cookie'));
    }
}
