<?php

namespace Database\Factories;

use App\Enums\PayBasis;
use App\Enums\VacationPolicy;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeePayrollProfile>
 */
class EmployeePayrollProfileFactory extends Factory
{
    protected $model = EmployeePayrollProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'contact_id' => Contact::factory()->state(['is_employee' => true]),
            'sin_encrypted' => '000000000',
            'sin_last4' => '0000',
            'date_of_birth' => '1990-01-15',
            'hire_date' => '2024-01-01',
            'termination_date' => null,
            'province_of_employment' => 'ON',
            'pay_basis' => PayBasis::Salary,
            'annual_salary_cents' => 6000000, // $60,000
            'hourly_rate_cents' => null,
            'default_hours_per_period' => null,
            'payroll_schedule_id' => null,
            'td1_federal_claim_cents' => 1659900,
            'td1_federal_code' => '1',
            'td1_provincial_claim_cents' => 1240000,
            'td1_provincial_code' => '1',
            'cpp_exempt' => false,
            'ei_exempt' => false,
            'additional_tax_per_period_cents' => 0,
            'vacation_policy' => VacationPolicy::Accrue,
            'vacation_rate_bp' => 400,
            'vacation_balance_cents' => 0,
            'is_active' => true,
        ];
    }

    public function hourly(int $rateCents = 3000, float $hours = 80): static
    {
        return $this->state(fn () => [
            'pay_basis' => PayBasis::Hourly,
            'annual_salary_cents' => null,
            'hourly_rate_cents' => $rateCents,
            'default_hours_per_period' => $hours,
        ]);
    }

    public function province(string $code): static
    {
        return $this->state(fn () => ['province_of_employment' => $code]);
    }
}
