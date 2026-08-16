<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\PayFrequency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'name', 'frequency', 'periods_per_year',
    'anchor_period_end_date', 'default_pay_offset_days', 'is_active',
])]
class PayrollSchedule extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * @return HasMany<EmployeePayrollProfile, $this>
     */
    public function employeeProfiles(): HasMany
    {
        return $this->hasMany(EmployeePayrollProfile::class);
    }

    /**
     * @return HasMany<PayRun, $this>
     */
    public function payRuns(): HasMany
    {
        return $this->hasMany(PayRun::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => PayFrequency::class,
            'periods_per_year' => 'integer',
            'anchor_period_end_date' => 'date:Y-m-d',
            'default_pay_offset_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
