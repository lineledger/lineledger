<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\PayBasis;
use App\Enums\VacationPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property PayBasis $pay_basis
 * @property VacationPolicy $vacation_policy
 * @property ?CarbonInterface $date_of_birth
 * @property ?CarbonInterface $hire_date
 * @property ?CarbonInterface $termination_date
 * @property ?CarbonInterface $cpt30_election_date
 * @property ?CarbonInterface $opening_balances_as_of
 * @property bool $banked_overtime_enabled
 * @property ?CarbonInterface $banked_overtime_agreement_date
 * @property ?int $banked_overtime_multiplier_bp
 */
#[Fillable([
    'company_id', 'contact_id', 'sin_encrypted', 'sin_last4',
    'date_of_birth', 'hire_date', 'termination_date', 'province_of_employment',
    'pay_basis', 'annual_salary_cents', 'hourly_rate_cents', 'default_hours_per_period',
    'payroll_schedule_id', 'td1_federal_claim_cents', 'td1_federal_code',
    'td1_provincial_claim_cents', 'td1_provincial_code', 'cpp_exempt', 'ei_exempt', 'qpip_exempt',
    'income_tax_exempt', 'workers_comp_exempt', 'workers_comp_rate_bp', 'cpt30_election_date',
    'additional_tax_per_period_cents', 'authorized_annual_deductions_cents', 'vacation_policy', 'vacation_rate_bp',
    'vacation_balance_cents', 'wage_expense_account_id', 'class_id', 'location_id', 'is_active',
    'banked_overtime_enabled', 'banked_overtime_agreement_date', 'banked_overtime_multiplier_bp',
    'approver_user_id',
    'opening_pensionable_cents', 'opening_insurable_cents', 'opening_cpp_employee_cents',
    'opening_cpp2_employee_cents', 'opening_ei_employee_cents', 'opening_qpp_employee_cents',
    'opening_qpp2_employee_cents', 'opening_qpip_employee_cents', 'opening_qpip_insurable_cents',
    'opening_balances_as_of',
])]
class EmployeePayrollProfile extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<PayrollSchedule, $this>
     */
    public function payrollSchedule(): BelongsTo
    {
        return $this->belongsTo(PayrollSchedule::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function wageExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'wage_expense_account_id');
    }

    /**
     * @return HasMany<EmployeeRecurringItem, $this>
     */
    public function recurringItems(): HasMany
    {
        return $this->hasMany(EmployeeRecurringItem::class)->orderBy('line_order');
    }

    /**
     * @return HasMany<EmployeeAccrualBalance, $this>
     */
    public function accrualBalances(): HasMany
    {
        return $this->hasMany(EmployeeAccrualBalance::class);
    }

    /**
     * @return HasMany<EmployeeTimeOffPolicy, $this>
     */
    public function timeOffPolicies(): HasMany
    {
        return $this->hasMany(EmployeeTimeOffPolicy::class);
    }

    /**
     * The company member who approves this employee's time-off requests
     * (step 1). Null = any payroll-section user handles both steps.
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /**
     * The time-off policies this employee can use: their active assignments
     * PLUS every active company default ("Use for new employees") — a default
     * the employer added later shouldn't be invisible to existing staff. First
     * use materializes the assignment via {@see ensureTimeOffPolicyAssigned()}.
     *
     * @return Collection<int, TimeOffPolicy>
     */
    public function availableTimeOffPolicies(): Collection
    {
        $assigned = $this->timeOffPolicies()
            ->where('is_active', true)
            ->with('policy')
            ->get()
            ->pluck('policy')
            ->filter(fn (?TimeOffPolicy $p): bool => $p !== null && $p->is_active);

        $defaults = TimeOffPolicy::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->get();

        return $assigned->concat($defaults)->unique('id')->sortBy('name')->values();
    }

    /**
     * Materialize a policy assignment (idempotent). Required before a leave
     * day can draw the employee's balance — the engine's time-off pricing and
     * the poster's drawdown both key off the ASSIGNED policies. Note this also
     * starts the policy's accrual method for the employee, which is exactly
     * what assignment means.
     */
    public function ensureTimeOffPolicyAssigned(TimeOffPolicy $policy): EmployeeTimeOffPolicy
    {
        return EmployeeTimeOffPolicy::firstOrCreate(
            ['employee_payroll_profile_id' => $this->id, 'time_off_policy_id' => $policy->id],
            ['is_active' => true],
        );
    }

    /**
     * Set the SIN, encrypting it and caching the last four digits for display.
     */
    public function setSin(?string $sin): void
    {
        $digits = $sin === null ? '' : preg_replace('/\D/', '', $sin);

        if ($digits === '' || $digits === null) {
            $this->sin_encrypted = null;
            $this->sin_last4 = null;

            return;
        }

        $this->sin_encrypted = $digits;
        $this->sin_last4 = mb_substr($digits, -4);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sin_encrypted' => 'encrypted',
            'date_of_birth' => 'date:Y-m-d',
            'hire_date' => 'date:Y-m-d',
            'termination_date' => 'date:Y-m-d',
            'pay_basis' => PayBasis::class,
            'annual_salary_cents' => 'integer',
            'hourly_rate_cents' => 'integer',
            'default_hours_per_period' => 'decimal:2',
            'td1_federal_claim_cents' => 'integer',
            'td1_provincial_claim_cents' => 'integer',
            'cpp_exempt' => 'boolean',
            'ei_exempt' => 'boolean',
            'qpip_exempt' => 'boolean',
            'income_tax_exempt' => 'boolean',
            'workers_comp_exempt' => 'boolean',
            'workers_comp_rate_bp' => 'integer',
            'cpt30_election_date' => 'date:Y-m-d',
            'additional_tax_per_period_cents' => 'integer',
            'authorized_annual_deductions_cents' => 'integer',
            'vacation_policy' => VacationPolicy::class,
            'vacation_rate_bp' => 'integer',
            'vacation_balance_cents' => 'integer',
            'banked_overtime_enabled' => 'boolean',
            'banked_overtime_agreement_date' => 'date:Y-m-d',
            'banked_overtime_multiplier_bp' => 'integer',
            'is_active' => 'boolean',
            'opening_pensionable_cents' => 'integer',
            'opening_insurable_cents' => 'integer',
            'opening_cpp_employee_cents' => 'integer',
            'opening_cpp2_employee_cents' => 'integer',
            'opening_ei_employee_cents' => 'integer',
            'opening_qpp_employee_cents' => 'integer',
            'opening_qpp2_employee_cents' => 'integer',
            'opening_qpip_employee_cents' => 'integer',
            'opening_qpip_insurable_cents' => 'integer',
            'opening_balances_as_of' => 'date:Y-m-d',
        ];
    }
}
