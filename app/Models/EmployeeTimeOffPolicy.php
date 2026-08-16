<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assignment of a {@see TimeOffPolicy} to an employee: an opening balance, an
 * optional per-employee rate override, and the last lump-grant date (so the
 * beginning-of-year / anniversary command stays idempotent).
 */
#[Fillable([
    'company_id', 'employee_payroll_profile_id', 'time_off_policy_id',
    'opening_balance_hours', 'opening_balance_cents', 'rate_override_hours',
    'rate_override_bp', 'effective_date', 'last_accrued_on', 'is_active',
])]
class EmployeeTimeOffPolicy extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * @return BelongsTo<TimeOffPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(TimeOffPolicy::class, 'time_off_policy_id');
    }

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
            'opening_balance_hours' => 'decimal:2',
            'opening_balance_cents' => 'integer',
            'rate_override_hours' => 'decimal:2',
            'rate_override_bp' => 'integer',
            'effective_date' => 'date:Y-m-d',
            'last_accrued_on' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }
}
