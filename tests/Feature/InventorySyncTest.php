<?php

namespace Tests\Feature;

use App\Jobs\SyncInventory;
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

class InventorySyncTest extends TestCase
{
    use RefreshDatabase;

    private ProductSku $sku;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Queue::fake();
        config([
            'services.woocommerce.base_url' => 'https://shop.example.se',
            'services.woocommerce.consumer_key' => 'ck_test',
            'services.woocommerce.consumer_secret' => 'cs_test',
        ]);

        $this->sku = ProductSku::factory()->create(['sku' => 'VC-TEA-1627', 'woo_product_id' => 17, 'woo_variation_id' => null]);
        InventoryCache::factory()->create([
            'product_sku_id' => $this->sku->id, 'warehouse_quantity' => 100, 'reserved_quantity' => 0, 'buffer_quantity' => 10,
        ]);
    }

    /** Fake Woo product endpoint that remembers the stock we write to it. */
    private function fakeWoo(?int $stock, bool $manageStock = true): void
    {
        $state = ['stock' => $stock, 'manage' => $manageStock];
        Http::fake(function (Request $r) use (&$state) {
            if ($r->method() === 'PUT') {
                $state = ['stock' => $r['stock_quantity'], 'manage' => $r['manage_stock']];
            }

            return Http::response(['id' => 17, 'manage_stock' => $state['manage'], 'stock_quantity' => $state['stock']]);
        });
    }

    private function sync(array $payload = []): SyncJob
    {
        $id = app(InventorySyncRequester::class)->request($this->sku, $payload)['sync_job_id'];
        app()->call([new SyncInventory($id), 'handle']);

        return SyncJob::find($id);
    }

    private function openOrder(string $status, array $lines): void
    {
        OrderLog::factory()->create([
            'status' => $status,
            'woo_payload' => ['id' => 1, 'line_items' => $lines],
            'priced_lines' => $status === OrderLog::STATUS_PRICED ? $lines : null,
        ]);
    }

    public function test_buffered_quantity_is_pushed_to_woo(): void
    {
        $this->fakeWoo(stock: 100);

        $job = $this->sync();

        $this->assertSame(SyncJob::STATUS_SUCCEEDED, $job->status);
        $this->assertSame(['warehouse' => 100, 'reserved' => 0, 'buffer' => 10, 'available' => 90, 'erp' => 'last known', 'woo_stock_before' => 100, 'pushed' => true],
            $job->payload['result']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'https://shop.example.se/wp-json/wc/v3/products/17'
            && $r['stock_quantity'] === 90 && $r['manage_stock'] === true);
        $this->assertNotNull($this->sku->inventory()->first()->woo_pushed_at);
    }

    public function test_open_orders_reserve_stock_until_they_reach_the_erp(): void
    {
        $this->openOrder(OrderLog::STATUS_RECEIVED, [['sku' => 'VC-TEA-1627', 'product_id' => 17, 'variation_id' => 0, 'quantity' => 12]]);
        $this->openOrder(OrderLog::STATUS_PRICED, [['sku' => 'VC-TEA-1627', 'quantity' => 6], ['sku' => 'VC-OTHER', 'quantity' => 99]]);
        $this->openOrder(OrderLog::STATUS_SYNCED, [['sku' => 'VC-TEA-1627', 'quantity' => 50]]);  // already in the ERP
        $this->openOrder(OrderLog::STATUS_FAILED, [['sku' => 'VC-TEA-1627', 'quantity' => 40]]);  // not a sale
        $this->fakeWoo(stock: 100);

        $result = $this->sync()->payload['result'];

        $this->assertSame(18, $result['reserved']);
        $this->assertSame(72, $result['available']);   // 100 − 18 − 10
    }

    public function test_reservation_matches_on_woo_ids_when_the_line_sku_differs(): void
    {
        $this->openOrder(OrderLog::STATUS_RECEIVED, [['sku' => 'OLD-SKU', 'product_id' => 17, 'variation_id' => 0, 'quantity' => 4]]);
        $this->fakeWoo(stock: 0);

        $this->assertSame(4, $this->sync()->payload['result']['reserved']);
    }

    public function test_available_never_goes_below_zero(): void
    {
        $this->openOrder(OrderLog::STATUS_RECEIVED, [['sku' => 'VC-TEA-1627', 'quantity' => 500]]);
        $this->fakeWoo(stock: 7);

        $this->assertSame(0, $this->sync()->payload['result']['available']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['stock_quantity'] === 0);
    }

    public function test_nothing_is_written_when_woo_is_already_correct_which_ends_the_webhook_loop(): void
    {
        $this->fakeWoo(stock: 100);

        $this->assertTrue($this->sync()->payload['result']['pushed']);     // 100 → 90, fires product.updated in real life
        $this->assertFalse($this->sync()->payload['result']['pushed']);    // the echo: Woo already says 90

        Http::assertSentCount(3); // GET + PUT, then GET only
    }

    public function test_stock_management_is_switched_on_in_woo_if_it_was_off(): void
    {
        $this->fakeWoo(stock: null, manageStock: false);

        $this->sync();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['manage_stock'] === true && $r['stock_quantity'] === 90);
    }

    public function test_variations_use_the_variation_endpoint(): void
    {
        $this->sku->update(['woo_product_id' => 30, 'woo_variation_id' => 31]);
        $this->fakeWoo(stock: 0);

        $this->sync();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/products/30/variations/31'));
    }

    public function test_new_sku_is_seeded_from_woos_stock_with_the_default_buffer(): void
    {
        config(['b2bsync.inventory.default_buffer' => 3]);
        $this->sku = ProductSku::factory()->create(['sku' => 'VC-NEW-1', 'woo_product_id' => 88]);
        $this->fakeWoo(stock: 25);

        $result = $this->sync(['woo_stock_quantity' => 25])->payload['result'];

        $this->assertSame(['warehouse' => 25, 'buffer' => 3, 'available' => 22], array_intersect_key($result, array_flip(['warehouse', 'buffer', 'available'])));
    }

    public function test_sku_without_woo_link_is_buffered_but_not_pushed(): void
    {
        $this->sku->update(['woo_product_id' => null]);
        Http::fake();

        $job = $this->sync();

        $this->assertSame(SyncJob::STATUS_SUCCEEDED, $job->status);
        $this->assertFalse($job->payload['result']['pushed']);
        Http::assertNothingSent();
    }

    public function test_woo_errors_mark_the_job_failed_and_say_whether_a_retry_makes_sense(): void
    {
        $status = 404;
        Http::fake(function () use (&$status) {
            return $status === 404
                ? Http::response(['code' => 'woocommerce_rest_product_invalid_id', 'message' => 'Invalid ID.'], 404)
                : Http::response('down', 503);
        });
        $deleted = $this->sync();
        $this->assertSame(SyncJob::STATUS_FAILED, $deleted->status);
        $this->assertStringContainsString('Invalid ID.', $deleted->last_error);
        $this->assertNull($deleted->next_retry_at);

        $status = 503;
        $down = $this->sync();
        $this->assertSame(SyncJob::STATUS_FAILED, $down->status);
        $this->assertNotNull($down->next_retry_at, '503 is worth retrying');
    }

    public function test_requests_are_merged_while_a_sync_is_waiting(): void
    {
        $requester = app(InventorySyncRequester::class);

        $first = $requester->request($this->sku, ['woo_stock_quantity' => 42]);
        $second = $requester->request($this->sku, ['woo_stock_quantity' => 40]);
        $third = $requester->request($this->sku, ['source' => 'manual']);

        $this->assertSame(['queued', 'coalesced', 'coalesced'], [$first['status'], $second['status'], $third['status']]);
        $payload = SyncJob::sole()->payload;
        $this->assertSame(40, $payload['woo_stock_quantity']);
        $this->assertSame(2, $payload['coalesced']);
        Queue::assertPushed(SyncInventory::class, 1);
    }

    public function test_inventory_set_command_updates_the_warehouse_and_queues_a_sync(): void
    {
        $this->artisan('inventory:set', ['sku' => 'VC-TEA-1627', 'warehouse' => 40, '--buffer' => 5])
            ->expectsOutputToContain('warehouse 40, buffer 5')
            ->assertSuccessful();

        $inventory = $this->sku->inventory()->first();
        $this->assertSame([40, 5, 35], [$inventory->warehouse_quantity, $inventory->buffer_quantity, $inventory->available_quantity]);
        Queue::assertPushed(SyncInventory::class);

        $this->artisan('inventory:set', ['sku' => 'NOPE', 'warehouse' => 1])->assertFailed();
        $this->artisan('inventory:set', ['sku' => 'VC-TEA-1627', 'warehouse' => -1])->assertFailed();
    }

    public function test_inventory_sync_command_queues_selected_or_all_linked_skus(): void
    {
        ProductSku::factory()->create(['sku' => 'VC-UNLINKED', 'woo_product_id' => null, 'is_active' => true]);

        $this->artisan('inventory:sync', ['--all' => true])->expectsOutputToContain('1 SKU(s): 1 queued')->assertSuccessful();
        $this->artisan('inventory:sync', ['sku' => ['VC-TEA-1627']])->expectsOutputToContain('1 merged')->assertSuccessful();
        $this->artisan('inventory:sync', ['sku' => ['NOPE']])->assertFailed();
    }

    public function test_a_pending_sync_whose_queue_message_was_lost_is_dispatched_again(): void
    {
        // A pending row left behind with nothing on the queue (e.g. created by older code).
        $orphan = $this->sku->syncJobs()->create(['type' => SyncJob::TYPE_INVENTORY_SYNC, 'status' => SyncJob::STATUS_PENDING, 'payload' => []]);

        $result = app(InventorySyncRequester::class)->request($this->sku, ['source' => 'manual']);

        $this->assertSame(['coalesced', $orphan->id], [$result['status'], $result['sync_job_id']]);
        Queue::assertPushed(SyncInventory::class, fn ($j) => $j->syncJobId === $orphan->id);

        // Recently dispatched: merged without queueing it a second time.
        app(InventorySyncRequester::class)->request($this->sku, ['source' => 'manual']);
        Queue::assertPushed(SyncInventory::class, 1);

        // …but once it has waited longer than the threshold, it is queued again.
        $this->travel(InventorySyncRequester::REDISPATCH_AFTER_MINUTES + 1)->minutes();
        app(InventorySyncRequester::class)->request($this->sku, ['source' => 'manual']);
        Queue::assertPushed(SyncInventory::class, 2);
    }

    public function test_a_job_left_running_by_a_dead_worker_is_taken_over_after_the_timeout(): void
    {
        $this->fakeWoo(stock: 100);
        $job = $this->sku->syncJobs()->create(['type' => SyncJob::TYPE_INVENTORY_SYNC, 'status' => SyncJob::STATUS_RUNNING,
            'started_at' => now(), 'attempts' => 1, 'payload' => []]);

        app()->call([new SyncInventory($job->id), 'handle']);
        $this->assertSame(SyncJob::STATUS_RUNNING, $job->fresh()->status, 'a live worker may still be on it');

        $this->travel(SyncJob::STUCK_AFTER_MINUTES + 1)->minutes();
        $this->artisan('sync:dispatch-pending', ['--type' => 'inventory_sync'])->expectsOutputToContain('1 inventory')->assertSuccessful();
        app()->call([new SyncInventory($job->id), 'handle']);

        $this->assertSame(SyncJob::STATUS_SUCCEEDED, $job->fresh()->status);
        $this->assertSame(2, $job->fresh()->attempts);
    }
}
