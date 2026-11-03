<?php

namespace App\Services\Orders;

use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Services\Pricing\PriceResolver;
use Illuminate\Support\Carbon;

/**
 * Prices the line items of a received WooCommerce order with the B2B customer's tier.
 *
 * - The tier is the one valid when the order was placed (received_at), not "now",
 *   so re-running an old order after a campaign ended gives the same result.
 * - Unknown customers (guest checkout, not in b2b_customers) pay list price.
 * - Every line must match a known SKU; otherwise the whole order is unpriceable.
 * - Woo's own charged price is kept next to ours, so differences are visible.
 */
class OrderPricer
{
    public function __construct(private readonly PriceResolver $prices) {}

    /**
     * @return array{lines: list<array<string, mixed>>, subtotal: string, tier: ?string}
     *
     * @throws UnpriceableOrderException
     */
    public function price(OrderLog $order): array
    {
        $items = $order->woo_payload['line_items'] ?? [];
        if ($items === []) {
            throw new UnpriceableOrderException('Order has no line items.');
        }

        $customer = $order->customer;
        $at = $order->received_at ?? Carbon::now();
        $tier = $customer?->is_active ? $this->prices->effectiveTier($customer, $at) : null;

        $skus = $this->matchSkus($items);
        $missing = collect($items)->reject(fn (array $item, int $i) => isset($skus[$i]))
            ->map(fn (array $item) => ($item['sku'] ?? '') !== '' ? $item['sku'] : "product #{$item['product_id']}")
            ->values();

        if ($missing->isNotEmpty()) {
            throw new UnpriceableOrderException('Unknown SKU: '.$missing->implode(', ').'. Add it to the catalogue (or update the product in WooCommerce) and retry.');
        }

        $subtotalCents = 0;
        $lines = [];
        foreach ($items as $i => $item) {
            $sku = $skus[$i];
            $quantity = (int) $item['quantity'];
            $baseCents = PriceResolver::toCents($sku->base_price);
            $unitCents = $tier ? PriceResolver::applyDiscount($baseCents, (string) $tier->discount_percent) : $baseCents;
            $lineCents = $unitCents * $quantity;
            $subtotalCents += $lineCents;

            $wooLineCents = PriceResolver::toCents(number_format((float) ($item['subtotal'] ?? 0), 2, '.', ''));

            $lines[] = [
                'woo_line_id' => $item['id'] ?? null,
                'sku' => $sku->sku,
                'fortnox_article_number' => $sku->fortnox_article_number,
                'name' => $item['name'] ?? $sku->name,
                'quantity' => $quantity,
                'base_price' => PriceResolver::fromCents($baseCents),
                'tier' => $tier?->slug,
                'discount_percent' => $tier ? (string) $tier->discount_percent : '0.00',
                'unit_price' => PriceResolver::fromCents($unitCents),
                'line_total' => PriceResolver::fromCents($lineCents),
                'woo_line_total' => PriceResolver::fromCents($wooLineCents),
                'difference' => PriceResolver::fromCents($lineCents - $wooLineCents),
            ];
        }

        return [
            'lines' => $lines,
            'subtotal' => PriceResolver::fromCents($subtotalCents),
            'tier' => $tier?->slug,
        ];
    }

    /**
     * SKU text first; fall back to the Woo product/variation id (e.g. SKU renamed in Woo).
     *
     * @return array<int, ProductSku> keyed by line index
     */
    private function matchSkus(array $items): array
    {
        $bySku = ProductSku::whereIn('sku', collect($items)->pluck('sku')->filter()->all())->get()->keyBy('sku');

        $matched = [];
        foreach ($items as $i => $item) {
            $sku = $bySku[$item['sku'] ?? ''] ?? null;

            if ($sku === null && ! empty($item['product_id'])) {
                $variationId = (int) ($item['variation_id'] ?? 0) ?: null;
                $sku = ProductSku::where('woo_product_id', $item['product_id'])
                    ->where(fn ($q) => $variationId ? $q->where('woo_variation_id', $variationId) : $q->whereNull('woo_variation_id'))
                    ->first();
            }

            if ($sku !== null) {
                $matched[$i] = $sku;
            }
        }

        return $matched;
    }
}
