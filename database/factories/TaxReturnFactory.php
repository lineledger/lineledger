<?php

namespace Database\Factories;

use App\Enums\TaxReturnStatus;
use App\Models\TaxAgency;
use App\Models\TaxReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxReturn>
 */
class TaxReturnFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tax_agency_id' => TaxAgency::factory(),
            'tax_return_no' => 'TR-'.fake()->unique()->numerify('######'),
            'period_start' => now()->startOfQuarter()->subQuarter()->toDateString(),
            'period_end' => now()->startOfQuarter()->subDay()->toDateString(),
            'status' => TaxReturnStatus::Draft,
            'collected_cents' => 0,
            'paid_cents' => 0,
            'net_cents' => 0,
        ];
    }

    public function filed(): static
    {
        return $this->state(fn () => [
            'status' => TaxReturnStatus::Filed,
            'filed_at' => now(),
        ]);
    }

    public function void(): static
    {
        return $this->state(fn () => [
            'status' => TaxReturnStatus::Void,
            'voided_at' => now(),
            'void_reason' => 'Test void',
        ]);
    }
}
