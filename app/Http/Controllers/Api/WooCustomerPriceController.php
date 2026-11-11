<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bCustomer;
use App\Models\ProductSku;
use App\Services\Pricing\PriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Prices for the shop front: what a logged-in WooCommerce customer pays for a batch of SKUs.
 *
 * Called by the "VerdantCart B2B Prices" WordPress plugin for product pages, cart and checkout.
 * It uses the same PriceResolver as order sync, so the price shown in the shop is the price
 * that ends up on the Fortnox order. Shoppers who are not active B2B customers get
 * `is_b2b: false` and no prices, which tells the plugin to keep WooCommerce's own price.
 */
class WooCustomerPriceController extends Controller
{
    public const MAX_SKUS = 100;

    /** GET /api/woo-customers/{wooCustomer}/prices?skus=A,B,C */
    public function index(Request $request, int $wooCustomer, PriceResolver $prices): JsonResponse
    {
        $skus = $this->requestedSkus($request);
        $cacheTtl = (int) config('b2bsync.storefront.cache_ttl', 300);

        $customer = B2bCustomer::where('woo_customer_id', $wooCustomer)->first();

        if ($customer === null || ! $customer->is_active) {
            return response()->json([
                'data' => [
                    'woo_customer_id' => $wooCustomer,
                    'is_b2b' => false,
                    'tier' => null,
                    'prices' => (object) [],
                    'missing' => [],
                ],
                'meta' => ['cache_ttl' => $cacheTtl],
            ]);
        }

        $tier = $prices->effectiveTier($customer, now());
        $quotes = ProductSku::whereIn('sku', $skus)->get()
            ->mapWithKeys(fn (ProductSku $sku) => [$sku->sku => $prices->quoteForTier($sku, $tier)])
            ->all();

        return response()->json([
            'data' => [
                'woo_customer_id' => $wooCustomer,
                'is_b2b' => true,
                'customer' => ['id' => $customer->id, 'company_name' => $customer->company_name],
                'tier' => $tier ? [
                    'slug' => $tier->slug,
                    'name' => $tier->name,
                    'discount_percent' => $tier->discount_percent,
                ] : null,
                'prices' => (object) collect($quotes)->map(fn ($quote) => [
                    'currency' => $quote->currency,
                    'base_price' => $quote->basePrice,
                    'unit_price' => $quote->unitPrice,
                ])->all(),
                'missing' => $skus->diff(array_keys($quotes))->values()->all(),
            ],
            'meta' => ['cache_ttl' => $cacheTtl],
        ]);
    }

    /** @return Collection<int, string> */
    private function requestedSkus(Request $request)
    {
        $request->validate(['skus' => ['required', 'string', 'max:20000']]);

        $skus = collect(explode(',', (string) $request->query('skus')))
            ->map(fn (string $sku) => trim($sku))
            ->filter(fn (string $sku) => $sku !== '')
            ->unique()
            ->values();

        if ($skus->isEmpty()) {
            throw ValidationException::withMessages(['skus' => 'Give at least one SKU, comma-separated.']);
        }
        if ($skus->count() > self::MAX_SKUS) {
            throw ValidationException::withMessages(['skus' => 'At most '.self::MAX_SKUS.' SKUs per request.']);
        }
        if ($skus->contains(fn (string $sku) => mb_strlen($sku) > 100)) {
            throw ValidationException::withMessages(['skus' => 'A SKU can be at most 100 characters.']);
        }

        return $skus;
    }
}
