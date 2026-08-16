<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'employee_payroll_profile_id', 'code', 'name',
    'balance_hours', 'balance_cents', 'accrued_ytd_hours', 'accrued_ytd_cents',
    'used_ytd_hours', 'used_ytd_cents',
])]
class EmployeeAccrualBalance extends Model
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
            'balance_hours' => 'decimal:2',
            'balance_cents' => 'integer',
            'accrued_ytd_hours' => 'decimal:2',
            'accrued_ytd_cents' => 'integer',
            'used_ytd_hours' => 'decimal:2',
            'used_ytd_cents' => 'integer',
        ];
    }
}
