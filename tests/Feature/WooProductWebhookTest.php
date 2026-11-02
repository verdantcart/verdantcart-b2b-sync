<?php

namespace Tests\Feature;

use App\Jobs\SyncInventory;
use App\Models\InventoryCache;
use App\Models\ProductSku;
use App\Models\SyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WooProductWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.woocommerce.webhook_secret' => self::SECRET]);
        Queue::fake();
    }

    private function product(array $overrides = []): array
    {
        $product = json_decode(file_get_contents(base_path('tests/Fixtures/woocommerce/product-updated.json')), true);

        return array_replace($product, $overrides);
    }

    private function deliver(array $payload, string $topic = 'product.updated'): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/woocommerce', [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'X-WC-Webhook-Topic' => $topic,
            'X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $body, self::SECRET, true)),
        ]), $body);
    }

    private function knownSku(): ProductSku
    {
        $sku = ProductSku::factory()->create([
            'sku' => 'VC-TEA-1627', 'woo_product_id' => 17, 'woo_variation_id' => null,
            'name' => 'Old name', 'base_price' => '99.00',
        ]);
        InventoryCache::factory()->create([
            'product_sku_id' => $sku->id, 'warehouse_quantity' => 100, 'reserved_quantity' => 0, 'buffer_quantity' => 10,
        ]);

        return $sku;
    }

    public function test_update_refreshes_catalogue_and_queues_one_inventory_sync(): void
    {
        $sku = $this->knownSku();

        $this->deliver($this->product())->assertOk()->assertJson(['status' => 'queued', 'product_sku_id' => $sku->id]);

        $sku->refresh();
        $this->assertSame('Rooibos 250 g', $sku->name);
        $this->assertSame('1431.59', $sku->base_price);

        $job = SyncJob::sole();
        $this->assertSame(SyncJob::TYPE_INVENTORY_SYNC, $job->type);
        $this->assertSame(SyncJob::STATUS_PENDING, $job->status);
        $this->assertTrue($job->syncable->is($sku));
        $this->assertSame(42, $job->payload['woo_stock_quantity']);

        Queue::assertPushedOn('inventory', SyncInventory::class, fn (SyncInventory $j) => $j->syncJobId === $job->id);
    }

    public function test_woo_stock_never_overwrites_the_erp_owned_buffer(): void
    {
        $sku = $this->knownSku();

        $this->deliver($this->product(['stock_quantity' => 3]))->assertOk();

        $inventory = $sku->inventory()->first();
        $this->assertSame(100, $inventory->warehouse_quantity);
        $this->assertSame(90, $inventory->available_quantity);
    }

    public function test_repeated_updates_are_coalesced_into_the_pending_job(): void
    {
        $this->knownSku();

        $this->deliver($this->product(['stock_quantity' => 42]))->assertJson(['status' => 'queued']);
        $this->deliver($this->product(['stock_quantity' => 40]))->assertJson(['status' => 'coalesced']);
        $this->deliver($this->product(['stock_quantity' => 37]))->assertJson(['status' => 'coalesced']);

        $job = SyncJob::sole();
        $this->assertSame(37, $job->payload['woo_stock_quantity'], 'keeps the latest stock Woo reported');
        $this->assertSame(2, $job->payload['coalesced']);
        Queue::assertPushed(SyncInventory::class, 1);
    }

    public function test_a_new_job_is_created_once_the_previous_one_has_finished(): void
    {
        $this->knownSku();
        $this->deliver($this->product());
        SyncJob::sole()->update(['status' => SyncJob::STATUS_SUCCEEDED]);

        $this->deliver($this->product())->assertJson(['status' => 'queued']);

        $this->assertSame(2, SyncJob::count());
        Queue::assertPushed(SyncInventory::class, 2);
    }

    public function test_unknown_sku_is_added_to_the_catalogue(): void
    {
        $this->deliver($this->product(['id' => 88, 'sku' => 'VC-NEW-0001', 'name' => 'Nytt te', 'regular_price' => '59.00']), 'product.created')
            ->assertOk()->assertJson(['status' => 'queued']);

        $sku = ProductSku::where('sku', 'VC-NEW-0001')->sole();
        $this->assertSame(88, $sku->woo_product_id);
        $this->assertSame('59.00', $sku->base_price);
        $this->assertNull($sku->fortnox_article_number);
    }

    public function test_variation_is_matched_on_its_woo_ids_even_if_the_sku_was_renamed(): void
    {
        $variation = ProductSku::factory()->create(['sku' => 'VC-TXT-OLD', 'woo_product_id' => 30, 'woo_variation_id' => 31]);

        $this->deliver($this->product(['id' => 31, 'parent_id' => 30, 'type' => 'variation', 'sku' => 'VC-TXT-NEW']))->assertOk();

        $variation->refresh();
        $this->assertSame('VC-TXT-NEW', $variation->sku);
        $this->assertSame(31, $variation->woo_variation_id);
        $this->assertSame(1, ProductSku::count());
    }

    public function test_variable_parents_and_products_without_sku_are_acknowledged_but_ignored(): void
    {
        $this->deliver($this->product(['type' => 'variable']))->assertStatus(202)->assertJson(['status' => 'ignored']);
        $this->deliver($this->product(['sku' => '']))->assertStatus(202)->assertJson(['status' => 'ignored']);

        $this->assertSame(0, ProductSku::count());
        $this->assertSame(0, SyncJob::count());
        Queue::assertNothingPushed();
    }

    public function test_unpublished_product_is_marked_inactive_and_missing_price_keeps_the_old_one(): void
    {
        $sku = $this->knownSku();

        $this->deliver($this->product(['status' => 'draft', 'regular_price' => '']))->assertOk();

        $sku->refresh();
        $this->assertFalse($sku->is_active);
        $this->assertSame('99.00', $sku->base_price);
    }
}
