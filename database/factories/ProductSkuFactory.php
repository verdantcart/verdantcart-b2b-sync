<?php

namespace Database\Factories;

use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Wholesale catalogue items a Nordic B2B brand might sell.
 *
 * @extends Factory<ProductSku>
 */
class ProductSkuFactory extends Factory
{
    private const PRODUCTS = [
        'KAF' => ['Kaffe mellanrost 1 kg', 'Kaffe mörkrost 1 kg', 'Espressobönor 1 kg', 'Bryggkaffe 500 g'],
        'TEA' => ['Grönt te 250 g', 'Rooibos 250 g', 'Earl Grey 250 g', 'Örtte fläder 100 g'],
        'TXT' => ['Linnehandduk', 'Kökshandduk 2-pack', 'Ullpläd grå', 'Bordsduk linne 140x250'],
        'LJS' => ['Stearinljus 12-pack', 'Doftljus granskog', 'Blockljus vit 10 cm', 'Värmeljus 100-pack'],
        'HUD' => ['Handkräm havtorn 75 ml', 'Tvål lavendel', 'Läppbalsam 4-pack', 'Duschtvål björk 300 ml'],
    ];

    public function definition(): array
    {
        $prefix = fake()->randomElement(array_keys(self::PRODUCTS));
        $number = fake()->unique()->numberBetween(1000, 9999);
        $isVariation = fake()->boolean(25);

        return [
            'sku' => "VC-{$prefix}-{$number}",
            'woo_product_id' => fake()->numberBetween(100, 5000),
            'woo_variation_id' => $isVariation ? fake()->numberBetween(5001, 9999) : null,
            'fortnox_article_number' => "{$prefix}{$number}",
            'name' => fake()->randomElement(self::PRODUCTS[$prefix]),
            'base_price' => fake()->randomFloat(2, 29, 1490),
            'currency' => 'SEK',
            'is_active' => fake()->boolean(95),
        ];
    }
}
