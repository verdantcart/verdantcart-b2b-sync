<?php

namespace App\Services\Pricing;

use App\Models\B2bPriceTier;

/** The price one customer pays for one SKU, and why. Money is kept as decimal strings. */
final readonly class PriceQuote
{
    public function __construct(
        public string $sku,
        public string $currency,
        public string $basePrice,
        public string $unitPrice,
        public ?B2bPriceTier $tier,
    ) {}

    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'currency' => $this->currency,
            'base_price' => $this->basePrice,
            'unit_price' => $this->unitPrice,
            'tier' => $this->tier ? [
                'slug' => $this->tier->slug,
                'name' => $this->tier->name,
                'discount_percent' => $this->tier->discount_percent,
            ] : null,
        ];
    }
}
