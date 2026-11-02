<?php

namespace Tests\Unit\Pricing;

use App\Services\Pricing\PriceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PriceResolverMathTest extends TestCase
{
    public static function discounts(): array
    {
        return [
            'no discount' => ['1431.59', '0.00', '1431.59'],
            '10 %' => ['1431.59', '10.00', '1288.43'],      // 1288.431 → .43
            '18 %' => ['100.00', '18.00', '82.00'],
            'half-up rounding' => ['0.05', '50.00', '0.03'], // 2.5 öre → 3
            'fractional %' => ['199.00', '12.50', '174.13'], // 174.125 → .13
            'float trap' => ['19.99', '15.00', '16.99'],     // 16.9915 → .99, no 16.98 from float error
            '100 %' => ['49.00', '100.00', '0.00'],
            'whole numbers' => ['250', '5', '237.50'],
        ];
    }

    #[DataProvider('discounts')]
    public function test_discount_maths_is_exact(string $base, string $discount, string $expected): void
    {
        $cents = PriceResolver::applyDiscount(PriceResolver::toCents($base), $discount);

        $this->assertSame($expected, PriceResolver::fromCents($cents));
    }

    public function test_cent_conversion_round_trips(): void
    {
        foreach (['0.00', '0.07', '12.30', '1431.59', '99999.99'] as $amount) {
            $this->assertSame($amount, PriceResolver::fromCents(PriceResolver::toCents($amount)));
        }
        $this->assertSame(1230, PriceResolver::toCents('12.3'));
    }
}
