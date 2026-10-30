<?php

namespace Database\Factories;

use App\Models\B2bPriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<B2bPriceTier>
 */
class B2bPriceTierFactory extends Factory
{
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
            'discount_percent' => fake()->randomElement([0, 5, 10, 15, 20]),
            'priority' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
