<?php

namespace App\Services\Pricing;

use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use App\Models\ProductSku;
use Illuminate\Support\Carbon;

/**
 * v0.1 pricing: unit price = base price × (1 − discount of the customer's best tier).
 *
 * "Best tier" = the active tier with the highest priority (ties: biggest discount) among the
 * customer's assignments that are valid at the given moment. Inactive customers get list price.
 * Maths is done in integer öre/cents with half-up rounding, never in floats.
 */
class PriceResolver
{
    public function quote(B2bCustomer $customer, ProductSku $sku, ?Carbon $at = null): PriceQuote
    {
        $tier = $customer->is_active ? $this->effectiveTier($customer, $at ?? now()) : null;
        $baseCents = self::toCents($sku->base_price);

        $unitCents = $tier
            ? self::applyDiscount($baseCents, (string) $tier->discount_percent)
            : $baseCents;

        return new PriceQuote(
            sku: $sku->sku,
            currency: $sku->currency,
            basePrice: self::fromCents($baseCents),
            unitPrice: self::fromCents($unitCents),
            tier: $tier,
        );
    }

    public function effectiveTier(B2bCustomer $customer, Carbon $at): ?B2bPriceTier
    {
        return $customer->priceTiers()
            ->where('b2b_price_tiers.is_active', true)
            ->where(fn ($q) => $q->whereNull('b2b_customer_tier_assignments.valid_from')
                ->orWhere('b2b_customer_tier_assignments.valid_from', '<=', $at))
            ->where(fn ($q) => $q->whereNull('b2b_customer_tier_assignments.valid_until')
                ->orWhere('b2b_customer_tier_assignments.valid_until', '>', $at))
            ->orderByDesc('b2b_price_tiers.priority')
            ->orderByDesc('b2b_price_tiers.discount_percent')
            ->first();
    }

    /** "1431.59" → 143159. Accepts up to 2 decimals, as stored in decimal(12,2). */
    public static function toCents(string|int|float $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '0');
        $sign = str_starts_with($whole, '-') ? -1 : 1;

        return $sign * (abs((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0'));
    }

    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Discount "12.50" % = 1250 basis points; result rounded half-up to whole cents. */
    public static function applyDiscount(int $cents, string $discountPercent): int
    {
        $basisPoints = self::toCents($discountPercent); // same 2-decimal parsing

        return intdiv($cents * (10000 - $basisPoints) + 5000, 10000);
    }
}
