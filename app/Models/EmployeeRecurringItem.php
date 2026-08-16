<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'employee_payroll_profile_id', 'kind', 'type', 'code', 'name',
    'calc_type', 'calc_basis', 'amount_cents', 'percent_bp', 'annual_maximum_cents', 'liability_account_id',
    'expense_account_id', 't4_box', 'reduces_taxable', 'is_active', 'line_order',
    'taxable_federal', 'taxable_provincial', 'cpp_qpp', 'qpip', 'ei_insurable_earnings',
    'ei_insurable_hours', 'wcb_eligible', 'tax_as_bonus', 'primary_earnings', 'add_to_net_pay_only',
    'add_to_bases_only', 'subtract_from_salary', 'stat_holiday_eligible', 'stat_holiday_payout',
    'pre_tax_federal', 'pre_tax_provincial',
])]
class EmployeeRecurringItem extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * @return BelongsTo<EmployeePayrollProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeePayrollProfile::class, 'employee_payroll_profile_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'percent_bp' => 'integer',
            'annual_maximum_cents' => 'integer',
            'reduces_taxable' => 'boolean',
            'is_active' => 'boolean',
            'line_order' => 'integer',
            'taxable_federal' => 'boolean',
            'taxable_provincial' => 'boolean',
            'cpp_qpp' => 'boolean',
            'qpip' => 'boolean',
            'ei_insurable_earnings' => 'boolean',
            'ei_insurable_hours' => 'boolean',
            'wcb_eligible' => 'boolean',
            'tax_as_bonus' => 'boolean',
            'primary_earnings' => 'boolean',
            'add_to_net_pay_only' => 'boolean',
            'add_to_bases_only' => 'boolean',
            'subtract_from_salary' => 'boolean',
            'stat_holiday_eligible' => 'boolean',
            'stat_holiday_payout' => 'boolean',
            'pre_tax_federal' => 'boolean',
            'pre_tax_provincial' => 'boolean',
        ];
    }
}
