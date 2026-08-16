<?php

namespace Database\Factories;

use App\Enums\Country;
use App\Enums\OrganizationType;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'is_personal' => false,
            'address_country' => Country::Canada->value,
            'currency_code' => 'CAD',
            'fiscal_year_start_month' => 1,
        ];
    }

    /**
     * A non-profit organization type.
     */
    public function nonProfit(): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_type' => OrganizationType::NonProfit->value,
        ]);
    }

    public function personal(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_personal' => true,
        ]);
    }

    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }

    public function forCountry(Country $country, ?string $region = null): static
    {
        return $this->state(fn (array $attributes) => [
            'address_country' => $country->value,
            'currency_code' => $country->defaultCurrencyCode(),
            'address_region' => $region,
        ]);
    }
}
