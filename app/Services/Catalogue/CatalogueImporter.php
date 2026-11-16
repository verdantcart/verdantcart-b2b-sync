<?php

namespace App\Services\Catalogue;

use App\Erp\ErpAdapter;
use App\Models\ProductSku;
use App\Services\Inventory\InventorySyncRequester;
use App\Services\WooCommerce\Webhooks\ProductUpdatedHandler;
use App\Services\WooCommerce\WooCommerceClient;

/**
 * Brings an existing shop into a new installation:
 *   1. every WooCommerce product and variation with a SKU becomes a product here
 *   2. products are linked to ERP articles (see ArticleMatcher)
 *   3. only linked products get a stock sync, so stock in the shop follows Fortnox
 *      and nothing is pushed for a product Fortnox doesn't know
 *
 * Safe to run again: existing products are updated, links are never changed.
 */
class CatalogueImporter
{
    /** How many unlinked products the result lists by name. */
    public const LIST_LIMIT = 25;

    public function __construct(
        private readonly ProductUpdatedHandler $products,
        private readonly InventorySyncRequester $inventory,
    ) {}

    /**
     * @param  null|callable(string): void  $progress
     * @return array{imported: int, skipped: int, linked: int, linked_before: int, unlinked: int, unlinked_list: list<array{id: int, sku: string, name: string, suggestion: ?string}>, stock_syncs: int}
     */
    public function run(WooCommerceClient $woo, ErpAdapter $erp, ?callable $progress = null): array
    {
        $progress ??= fn () => null;
        $imported = $skipped = 0;
        $gtins = [];

        foreach ($woo->paginate('products', ['status' => 'any']) as $product) {
            $rows = ($product['type'] ?? null) === 'variable'
                ? $woo->paginate("products/{$product['id']}/variations")->map(fn (array $v) => $v + [
                    'type' => 'variation',
                    'parent_id' => $product['id'],
                    'name' => trim(($product['name'] ?? '').' '.collect($v['attributes'] ?? [])->pluck('option')->implode(' ')),
                ])
                : [$product];

            foreach ($rows as $row) {
                $sku = $this->products->save($row);
                if ($sku === null) {
                    $skipped++;

                    continue;
                }
                $imported++;
                if ($gtin = $row['global_unique_id'] ?? null) {
                    $gtins[$sku->id] = (string) $gtin;
                }
            }
            $progress("{$imported} products imported");
        }

        $progress('Matching products with Fortnox articles');
        $unlinkedSkus = ProductSku::whereNull('fortnox_article_number')->orderBy('sku')->get();
        $taken = ProductSku::whereNotNull('fortnox_article_number')->pluck('fortnox_article_number')->all();
        $match = ArticleMatcher::match($unlinkedSkus, $erp->pullArticles(), $gtins, $taken);

        foreach ($match['linked'] as $id => $link) {
            ProductSku::whereKey($id)->update(['fortnox_article_number' => $link['number']]);
        }

        $progress('Queueing stock updates');
        $stockSyncs = 0;
        ProductSku::whereNotNull('fortnox_article_number')->where('is_active', true)->whereNotNull('woo_product_id')
            ->chunkById(200, function ($skus) use (&$stockSyncs) {
                foreach ($skus as $sku) {
                    $this->inventory->request($sku, ['source' => 'catalogue-import']);
                    $stockSyncs++;
                }
            });

        $stillUnlinked = $unlinkedSkus->reject(fn (ProductSku $s) => isset($match['linked'][$s->id]))->values();

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'linked' => count($match['linked']),
            'linked_before' => count($taken),
            'unlinked' => $stillUnlinked->count(),
            'unlinked_list' => $stillUnlinked->take(self::LIST_LIMIT)->map(fn (ProductSku $s) => [
                'id' => $s->id, 'sku' => $s->sku, 'name' => (string) $s->name, 'suggestion' => $match['suggested'][$s->id] ?? null,
            ])->all(),
            'stock_syncs' => $stockSyncs,
        ];
    }
}
