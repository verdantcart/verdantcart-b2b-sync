<?php

namespace Tests\Feature;

use App\Erp\ErpAdapter;
use App\Erp\ErpArticle;
use App\Erp\FakeErpAdapter;
use App\Jobs\SyncInventory;
use App\Models\ProductSku;
use App\Services\Catalogue\ArticleMatcher;
use App\Services\Catalogue\CatalogueImporter;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CatalogueImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config([
            'services.woocommerce.base_url' => 'https://shop.acme.test',
            'services.woocommerce.consumer_key' => 'ck',
            'services.woocommerce.consumer_secret' => 'cs',
        ]);
        $this->app->forgetInstance(WooCommerceClient::class);
    }

    /** A Woo store with a simple product, a variable product with two variations, and one without SKU. */
    private function fakeStore(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'shop.acme.test/wp-json/wc/v3/products/20/variations*' => Http::response([
                ['id' => 21, 'sku' => 'MUG-RED', 'regular_price' => '150.00', 'status' => 'publish', 'attributes' => [['option' => 'Red']]],
                ['id' => 22, 'sku' => 'MUG-BLUE', 'regular_price' => '150.00', 'status' => 'publish', 'attributes' => [['option' => 'Blue']]],
            ], 200, ['X-WP-TotalPages' => '1']),
            'shop.acme.test/wp-json/wc/v3/products*' => Http::response([
                ['id' => 10, 'type' => 'simple', 'sku' => 'TEA-100', 'name' => 'Green tea', 'regular_price' => '200.00', 'status' => 'publish', 'global_unique_id' => ''],
                ['id' => 20, 'type' => 'variable', 'sku' => 'MUG', 'name' => 'Mug', 'regular_price' => '', 'status' => 'publish'],
                ['id' => 30, 'type' => 'simple', 'sku' => '', 'name' => 'Gift wrap', 'regular_price' => '20.00', 'status' => 'publish'],
                ['id' => 40, 'type' => 'simple', 'sku' => 'VC-KAF-4735', 'name' => 'Coffee', 'regular_price' => '180.00', 'status' => 'publish'],
                ['id' => 50, 'type' => 'simple', 'sku' => 'HONEY-1', 'name' => 'Honey', 'regular_price' => '90.00', 'status' => 'publish', 'global_unique_id' => '7350000000017'],
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);
    }

    private function erp(array $articles): FakeErpAdapter
    {
        return new class($articles) extends FakeErpAdapter
        {
            public function __construct(private array $articles) {}

            public function pullArticles(): iterable
            {
                return $this->articles;
            }
        };
    }

    // --- Matching --------------------------------------------------------------------------

    public function test_articles_are_matched_by_number_then_without_punctuation_then_by_ean(): void
    {
        $skus = collect([
            new ProductSku(['sku' => 'tea-100']), new ProductSku(['sku' => 'MUG-RED']), new ProductSku(['sku' => 'HONEY-1']),
            new ProductSku(['sku' => 'VC-KAF-4735']), new ProductSku(['sku' => 'NOPE']),
        ])->each(fn ($s, $i) => $s->id = $i + 1);

        $result = ArticleMatcher::match($skus, [
            new ErpArticle('TEA-100'), new ErpArticle('MUGRED'), new ErpArticle('H1', ean: '7350000000017'), new ErpArticle('KAF4735'),
        ], gtins: [3 => '7350000000017']);

        $this->assertSame([
            1 => ['number' => 'TEA-100', 'how' => 'exact'],
            2 => ['number' => 'MUGRED', 'how' => 'normalised'],
            3 => ['number' => 'H1', 'how' => 'ean'],
        ], $result['linked']);
        $this->assertSame([4 => 'KAF4735'], $result['suggested'], 'a suffix match is only suggested');
    }

    public function test_ambiguous_taken_or_expired_articles_are_never_linked(): void
    {
        $skus = collect([new ProductSku(['sku' => 'A-1']), new ProductSku(['sku' => 'B-2']), new ProductSku(['sku' => 'C-3'])])
            ->each(fn ($s, $i) => $s->id = $i + 1);

        $result = ArticleMatcher::match($skus, [
            new ErpArticle('A1'), new ErpArticle('a.1'),    // two candidates for A-1
            new ErpArticle('B-2'),                           // already linked elsewhere
            new ErpArticle('C-3', active: false),            // expired in Fortnox
        ], taken: ['b-2']);

        $this->assertSame([], $result['linked']);
    }

    // --- Import ----------------------------------------------------------------------------

    public function test_the_catalogue_is_imported_with_variations_and_linked(): void
    {
        Queue::fake([SyncInventory::class]);
        $this->fakeStore();

        $result = app(CatalogueImporter::class)->run(app(WooCommerceClient::class), $this->erp([
            new ErpArticle('TEA-100'), new ErpArticle('MUGRED'), new ErpArticle('MUGBLUE'), new ErpArticle('H1', ean: '7350000000017'), new ErpArticle('KAF4735'),
        ]));

        $this->assertSame([5, 1, 4, 1], [$result['imported'], $result['skipped'], $result['linked'], $result['unlinked']]);
        $this->assertSame([['id' => ProductSku::where('sku', 'VC-KAF-4735')->value('id'), 'sku' => 'VC-KAF-4735', 'name' => 'Coffee', 'suggestion' => 'KAF4735']], $result['unlinked_list']);

        $red = ProductSku::where('sku', 'MUG-RED')->sole();
        $this->assertSame([20, 21, 'Mug Red', '150.00', 'MUGRED'], [$red->woo_product_id, $red->woo_variation_id, $red->name, $red->base_price, $red->fortnox_article_number]);
        $this->assertSame('H1', ProductSku::where('sku', 'HONEY-1')->value('fortnox_article_number'));
    }

    public function test_only_linked_products_get_a_stock_sync(): void
    {
        Queue::fake([SyncInventory::class]);
        $this->fakeStore();

        $result = app(CatalogueImporter::class)->run(app(WooCommerceClient::class), $this->erp([new ErpArticle('TEA-100')]));

        $this->assertSame(1, $result['stock_syncs']);
        Queue::assertPushed(SyncInventory::class, 1); // the unlinked ones keep the shop's own stock
    }

    public function test_importing_again_keeps_links_made_by_hand(): void
    {
        Queue::fake([SyncInventory::class]);
        $this->fakeStore();
        ProductSku::factory()->create(['sku' => 'VC-KAF-4735', 'woo_product_id' => 40, 'fortnox_article_number' => 'COFFEE-OLD']);

        app(CatalogueImporter::class)->run(app(WooCommerceClient::class), $this->erp([new ErpArticle('KAF4735')]));

        $this->assertSame('COFFEE-OLD', ProductSku::where('sku', 'VC-KAF-4735')->value('fortnox_article_number'));
        $this->assertSame(1, ProductSku::where('sku', 'VC-KAF-4735')->count());
    }

    public function test_the_command_lists_what_is_not_linked(): void
    {
        Queue::fake([SyncInventory::class]);
        $this->fakeStore();
        $this->app->instance(ErpAdapter::class, $this->erp([new ErpArticle('KAF4735')]));

        $this->artisan('woo:pull-products')
            ->expectsOutputToContain('5 products imported (1 without SKU skipped). 0 newly linked')
            ->expectsTable(['SKU', 'Name', 'Suggested article'], [
                ['HONEY-1', 'Honey', ''], ['MUG-BLUE', 'Mug Blue', ''], ['MUG-RED', 'Mug Red', ''], ['TEA-100', 'Green tea', ''], ['VC-KAF-4735', 'Coffee', 'KAF4735'],
            ])
            ->assertSuccessful();
    }
}
