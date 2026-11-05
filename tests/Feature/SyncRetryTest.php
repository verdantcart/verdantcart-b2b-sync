<?php

namespace Tests\Feature;

use App\Erp\ErpAdapter;
use App\Erp\ErpException;
use App\Erp\FakeErpAdapter;
use App\Erp\Fortnox\FortnoxAdapter;
use App\Erp\Fortnox\FortnoxClient;
use App\Jobs\SyncInventory;
use App\Jobs\SyncOrderToErp;
use App\Models\B2bCustomer;
use App\Models\ErpConnection;
use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\SyncJob;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SyncRetryTest extends TestCase
{
    use RefreshDatabase;

    private function pricedOrder(int $wooId = 28): OrderLog
    {
        $customer = B2bCustomer::factory()->create(['fortnox_customer_number' => '2041']);

        return OrderLog::factory()->for($customer, 'customer')->create([
            'woo_order_id' => $wooId,
            'status' => OrderLog::STATUS_PRICED,
            'priced_lines' => [['sku' => 'VC-TXT-5432', 'fortnox_article_number' => 'TXT5432', 'name' => 'Ullpläd grå', 'quantity' => 1, 'unit_price' => '1117.12', 'tier' => 'gold']],
        ]);
    }

    private function orderJob(OrderLog $order): SyncJob
    {
        return $order->syncJobs()->create(['type' => SyncJob::TYPE_ORDER_PUSH, 'status' => SyncJob::STATUS_PENDING]);
    }

    /** An ERP that fails the way we tell it to. */
    private function erpFailing(ErpException $e): void
    {
        $this->app->instance(ErpAdapter::class, new class($e) extends FakeErpAdapter
        {
            public function __construct(private ErpException $e) {}

            public function pushOrder(OrderLog $order): string
            {
                throw $this->e;
            }
        });
    }

    private function run_(SyncJob $job): SyncJob
    {
        app()->call([new SyncOrderToErp($job->id), 'handle']);

        return $job->fresh();
    }

    // --- backoff ---------------------------------------------------------------

    public function test_backoff_doubles_and_is_capped(): void
    {
        $this->assertSame([1, 2, 4, 8, 16, 32, 60, 60], array_map(SyncJob::retryDelayMinutes(...), [1, 2, 3, 4, 5, 6, 7, 8]));
    }

    public function test_retryable_erp_error_is_scheduled_with_growing_delays_then_dead(): void
    {
        $this->erpFailing(new ErpException('Fortnox 503', retryable: true, status: 503));
        $job = $this->orderJob($this->pricedOrder());

        $delays = [];
        for ($i = 1; $i <= SyncJob::MAX_ATTEMPTS; $i++) {
            $job = $this->run_($job);
            $delays[] = $job->next_retry_at ? (int) round(now()->diffInMinutes($job->next_retry_at)) : null;
        }

        $this->assertSame([1, 2, 4, 8, 16, null], $delays);
        $this->assertSame(SyncJob::STATUS_DEAD, $job->status);
        $this->assertSame(OrderLog::STATUS_PRICED, $job->syncable->status, 'a dead job still leaves a valid, stock-reserving order');
    }

    public function test_non_retryable_error_waits_for_a_person(): void
    {
        $this->erpFailing(new ErpException('Förvalt konto för Försäljning saknas', retryable: false, status: 400));

        $job = $this->run_($this->orderJob($this->pricedOrder()));

        $this->assertSame(SyncJob::STATUS_FAILED, $job->status);
        $this->assertNull($job->next_retry_at);
    }

    // --- sync:retry-due --------------------------------------------------------

    public function test_retry_due_requeues_only_jobs_whose_time_has_come_and_stuck_ones(): void
    {
        Queue::fake();
        $order = $this->pricedOrder();
        $due = $order->syncJobs()->create(['type' => SyncJob::TYPE_ORDER_PUSH, 'status' => SyncJob::STATUS_FAILED, 'next_retry_at' => now()->subSecond()]);
        $later = $order->syncJobs()->create(['type' => SyncJob::TYPE_ORDER_PUSH, 'status' => SyncJob::STATUS_FAILED, 'next_retry_at' => now()->addMinutes(5)]);
        $manual = $order->syncJobs()->create(['type' => SyncJob::TYPE_ORDER_PUSH, 'status' => SyncJob::STATUS_FAILED, 'next_retry_at' => null]);
        $stuck = ProductSku::factory()->create()->syncJobs()->create(['type' => SyncJob::TYPE_INVENTORY_SYNC, 'status' => SyncJob::STATUS_RUNNING, 'started_at' => now()->subHour()]);

        $this->artisan('sync:retry-due')->expectsOutputToContain('Re-queued 2')->assertSuccessful();

        Queue::assertPushed(SyncOrderToErp::class, fn ($j) => $j->syncJobId === $due->id);
        Queue::assertPushed(SyncInventory::class, fn ($j) => $j->syncJobId === $stuck->id);
        Queue::assertNotPushed(SyncOrderToErp::class, fn ($j) => in_array($j->syncJobId, [$later->id, $manual->id], true));

        $this->artisan('sync:retry-due')->expectsOutputToContain('Re-queued 1')->assertSuccessful(); // only the stuck one is still "running"
    }

    public function test_retry_due_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('sync:retry-due')->assertSuccessful();
    }

    // --- never two ERP orders --------------------------------------------------

    public function test_after_a_crash_mid_push_the_existing_erp_order_is_linked_not_recreated(): void
    {
        $order = $this->pricedOrder();
        $job = $this->orderJob($order);
        // Previous attempt: marker written, ERP accepted the order, worker died before saving the number.
        $job->update(['status' => SyncJob::STATUS_RUNNING, 'started_at' => now()->subHour(), 'attempts' => 1, 'payload' => ['erp_push_started_at' => now()->subHour()->toIso8601String()]]);

        $erp = new class extends FakeErpAdapter
        {
            public int $pushes = 0;

            public function findOrder(OrderLog $order): ?string
            {
                return '5';
            }

            public function pushOrder(OrderLog $order): string
            {
                $this->pushes++;

                return 'NEW';
            }
        };
        $this->app->instance(ErpAdapter::class, $erp);

        $job = $this->run_($job);

        $this->assertSame(0, $erp->pushes);
        $this->assertSame([OrderLog::STATUS_SYNCED, '5'], [$order->fresh()->status, $order->fresh()->erp_document_number]);
        $this->assertSame(SyncJob::STATUS_SUCCEEDED, $job->status);
    }

    public function test_first_attempt_does_not_waste_a_lookup(): void
    {
        $erp = new class extends FakeErpAdapter
        {
            public int $lookups = 0;

            public function findOrder(OrderLog $order): ?string
            {
                $this->lookups++;

                return null;
            }
        };
        $this->app->instance(ErpAdapter::class, $erp);

        $this->run_($this->orderJob($this->pricedOrder()));

        $this->assertSame(0, $erp->lookups);
    }

    public function test_fortnox_find_order_matches_exactly_on_the_woo_order_number(): void
    {
        Sleep::fake();
        config(['erp.fortnox.client_id' => 'c', 'erp.fortnox.client_secret' => 's']);
        $this->app->forgetInstance(FortnoxClient::class);
        ErpConnection::create(['driver' => 'fortnox', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'connected_at' => now()]);
        Http::fake(function (Request $r) {
            return match (true) {
                str_contains($r->url(), 'externalinvoicereference1') => Http::response(['Orders' => [
                    ['DocumentNumber' => '7', 'ExternalInvoiceReference1' => 'Woo #280'], // "contains" match, wrong order
                    ['DocumentNumber' => '5', 'ExternalInvoiceReference1' => 'Woo #28'],
                ]]),
                str_ends_with($r->url(), '/orders/5') => Http::response(['Order' => ['DocumentNumber' => '5', 'YourOrderNumber' => '28', 'Cancelled' => false]]),
                default => Http::response(['Order' => ['DocumentNumber' => '?', 'YourOrderNumber' => 'x']]),
            };
        });

        $this->assertSame('5', app(FortnoxAdapter::class)->findOrder($this->pricedOrder(28)));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'externalinvoicereference1=Woo #28'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/orders/7'));
    }

    public function test_fortnox_find_order_ignores_cancelled_orders(): void
    {
        config(['erp.fortnox.client_id' => 'c', 'erp.fortnox.client_secret' => 's']);
        $this->app->forgetInstance(FortnoxClient::class);
        ErpConnection::create(['driver' => 'fortnox', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'connected_at' => now()]);
        Http::fake([
            '*/orders?*' => Http::response(['Orders' => [['DocumentNumber' => '5']]]),
            '*/orders/5' => Http::response(['Order' => ['DocumentNumber' => '5', 'YourOrderNumber' => '28', 'Cancelled' => true]]),
        ]);

        $this->assertNull(app(FortnoxAdapter::class)->findOrder($this->pricedOrder(28)));
    }

    // --- failed jobs page --------------------------------------------------------

    public function test_failed_jobs_page_lists_failures_and_retry_requeues(): void
    {
        app()->detectEnvironment(fn () => 'local');
        Queue::fake();
        $order = $this->pricedOrder();
        $dead = $order->syncJobs()->create(['type' => SyncJob::TYPE_ORDER_PUSH, 'status' => SyncJob::STATUS_DEAD, 'attempts' => 6, 'last_error' => 'Fortnox 503: Service unavailable']);

        $this->get('/sync-jobs')->assertOk()->assertSee('Woo order #28')->assertSee('Fortnox 503: Service unavailable');

        $this->withoutMiddleware(PreventRequestForgery::class)->post("/sync-jobs/{$dead->id}/retry")->assertRedirect();

        Queue::assertPushed(SyncOrderToErp::class, fn ($j) => $j->syncJobId === $dead->id);
        $this->assertSame([SyncJob::STATUS_FAILED, 0], [$dead->fresh()->status, $dead->fresh()->attempts], 'manual retry gives a dead job fresh attempts');
    }

    public function test_failed_jobs_page_is_admin_only_outside_local(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['horizon.basic_auth.password' => null]);

        $this->get('/sync-jobs')->assertForbidden();
        $this->post('/sync-jobs/1/retry')->assertStatus(419); // no CSRF token: refused before anything else
        $this->withoutMiddleware(PreventRequestForgery::class)->post('/sync-jobs/1/retry')->assertForbidden();
    }
}
