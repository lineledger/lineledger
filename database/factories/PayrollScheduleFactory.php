<?php

namespace Database\Factories;

use App\Enums\PayFrequency;
use App\Models\Company;
use App\Models\PayrollSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollSchedule>
 */
class PayrollScheduleFactory extends Factory
{
    protected $model = PayrollSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $frequency = PayFrequency::Biweekly;

        return [
            'company_id' => Company::factory(),
            'name' => $frequency->label().' payroll',
            'frequency' => $frequency,
            'periods_per_year' => $frequency->periodsPerYear(),
            'anchor_period_end_date' => '2026-01-09',
            'default_pay_offset_days' => 5,
            'is_active' => true,
        ];
    }

    public function frequency(PayFrequency $frequency): static
    {
        return $this->state(fn () => [
            'frequency' => $frequency,
            'periods_per_year' => $frequency->periodsPerYear(),
            'name' => $frequency->label().' payroll',
        ]);
    }
}
