<?php

namespace App\Services\Catalogue;

use App\Erp\ErpArticle;
use App\Models\ProductSku;

/**
 * Pairs WooCommerce products with ERP articles.
 *
 * Linked straight away (unambiguous):
 *   1. the article number is the SKU, ignoring upper/lower case
 *   2. the same after removing spaces, dashes, dots and slashes ("VC-KAF-4735" = "VCKAF4735")
 *   3. the article's EAN is the product's GTIN/EAN
 * Only suggested (a person confirms):
 *   4. one of the two ends with the other, after step 2 ("VC-KAF-4735" ~ "KAF4735"), and no
 *      other article does
 * An article already linked to another product is never offered again.
 */
class ArticleMatcher
{
    public const EXACT = 'exact';

    public const NORMALISED = 'normalised';

    public const EAN = 'ean';

    public const SUFFIX = 'suffix';

    /**
     * @param  iterable<ProductSku>  $skus  products without an article number
     * @param  iterable<ErpArticle>  $articles
     * @param  array<int, string>  $gtins  product_sku_id => GTIN/EAN from WooCommerce
     * @param  list<string>  $taken  article numbers already linked to a product
     * @return array{linked: array<int, array{number: string, how: string}>, suggested: array<int, string>}
     */
    public static function match(iterable $skus, iterable $articles, array $gtins = [], array $taken = []): array
    {
        $taken = array_flip(array_map('mb_strtoupper', $taken));
        $byUpper = $byNormalised = $byEan = [];
        foreach ($articles as $article) {
            if (! $article->active || isset($taken[mb_strtoupper($article->number)])) {
                continue;
            }
            $byUpper[mb_strtoupper($article->number)][] = $article->number;
            $byNormalised[self::normalise($article->number)][] = $article->number;
            if ($article->ean) {
                $byEan[trim($article->ean)][] = $article->number;
            }
        }

        $linked = $suggested = $used = [];
        $pick = function (array $candidates) use (&$used): ?string {
            $free = array_values(array_unique(array_filter($candidates, fn ($n) => ! isset($used[$n]))));

            return count($free) === 1 ? $free[0] : null;
        };

        foreach ($skus as $sku) {
            $gtin = isset($gtins[$sku->id]) ? trim($gtins[$sku->id]) : '';
            foreach ([
                self::EXACT => $byUpper[mb_strtoupper($sku->sku)] ?? [],
                self::NORMALISED => $byNormalised[self::normalise($sku->sku)] ?? [],
                self::EAN => $gtin !== '' ? ($byEan[$gtin] ?? []) : [],
            ] as $how => $candidates) {
                if ($number = $pick($candidates)) {
                    $linked[$sku->id] = ['number' => $number, 'how' => $how];
                    $used[$number] = true;

                    continue 2;
                }
            }
        }

        // Suggestions only for what is still unlinked, and only when exactly one article fits.
        foreach ($skus as $sku) {
            if (isset($linked[$sku->id])) {
                continue;
            }
            $own = self::normalise($sku->sku);
            if (strlen($own) < 4) {
                continue; // too short to say anything
            }
            $fits = [];
            foreach ($byNormalised as $normalised => $numbers) {
                if (strlen((string) $normalised) >= 4 && (str_ends_with($own, (string) $normalised) || str_ends_with((string) $normalised, $own))) {
                    array_push($fits, ...$numbers);
                }
            }
            if ($number = $pick($fits)) {
                $suggested[$sku->id] = $number;
            }
        }

        return ['linked' => $linked, 'suggested' => $suggested];
    }

    public static function normalise(string $number): string
    {
        return mb_strtoupper(preg_replace('/[\s\-._\/]+/u', '', $number));
    }
}
