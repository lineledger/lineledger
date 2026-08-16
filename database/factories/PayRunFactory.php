<?php

namespace Database\Factories;

use App\Enums\PayRunStatus;
use App\Models\Company;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayRun>
 */
class PayRunFactory extends Factory
{
    protected $model = PayRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'payroll_schedule_id' => PayrollSchedule::factory(),
            'run_no' => 'PR-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'period_start_date' => '2025-06-01',
            'period_end_date' => '2025-06-14',
            'pay_date' => '2025-06-20',
            'status' => PayRunStatus::Draft,
            'gross_cents' => 0,
            'total_deductions_cents' => 0,
            'total_employer_cost_cents' => 0,
            'net_cents' => 0,
        ];
    }
}
