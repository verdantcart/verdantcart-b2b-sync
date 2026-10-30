<?php

namespace Database\Factories;

use App\Models\B2bCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Swedish B2B buyers with checksum-valid organisationsnummer.
 *
 * @extends Factory<B2bCustomer>
 */
class B2bCustomerFactory extends Factory
{
    public function definition(): array
    {
        $faker = fake('sv_SE');
        $company = $faker->unique()->lastName().' '.$faker->randomElement(
            ['Handel AB', 'Grossist AB', 'Butiker AB', 'Interiör AB', 'Café & Deli AB', 'Trading AB']
        );
        $orgNumber = self::orgNumber();

        return [
            'woo_customer_id' => fake()->unique()->numberBetween(1000, 99999),
            'fortnox_customer_number' => (string) fake()->unique()->numberBetween(100, 9999),
            'company_name' => $company,
            'org_number' => $orgNumber,
            'vat_number' => 'SE'.str_replace('-', '', $orgNumber).'01',
            'email' => 'inkop@'.fake()->unique()->domainWord().'.se',
            'country_code' => 'SE',
            'currency' => 'SEK',
            'is_active' => fake()->boolean(92),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /** Not yet matched to a Fortnox customer. */
    public function unmatched(): static
    {
        return $this->state(['fortnox_customer_number' => null]);
    }

    /**
     * 10-digit Swedish org number (NNNNNN-NNNN) with a valid Luhn check digit.
     * "55xxxx" = aktiebolag; third digit >= 2 per Bolagsverket rules.
     */
    public static function orgNumber(): string
    {
        $digits = '55'.fake()->numberBetween(2, 9).fake()->numerify('######');

        $sum = 0;
        foreach (str_split($digits) as $i => $d) {
            $n = (int) $d * ($i % 2 === 0 ? 2 : 1);
            $sum += $n > 9 ? $n - 9 : $n;
        }
        $digits .= (10 - $sum % 10) % 10;

        return substr($digits, 0, 6).'-'.substr($digits, 6);
    }
}
