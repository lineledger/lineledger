<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\TimeOffAccrualMethod;
use App\Enums\TimeOffCategory;
use App\Enums\TimeOffUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company-level time-off preset: how a leave type accrues (method + rate),
 * its annual cap and carryover limit, and whether it is paid. Assigned to
 * employees via {@see EmployeeTimeOffPolicy}; balances are tracked in
 * {@see EmployeeAccrualBalance} keyed on this policy's `code`.
 *
 * @property string $code
 * @property string $name
 * @property TimeOffCategory $category
 * @property TimeOffUnit $unit
 * @property TimeOffAccrualMethod $accrual_method
 * @property float $rate_hours
 * @property int $rate_bp
 * @property float|null $annual_cap_hours
 * @property int|null $annual_cap_cents
 * @property float|null $carryover_max_hours
 * @property int|null $carryover_max_cents
 * @property bool $paid
 * @property bool $is_default
 * @property bool $is_active
 */
#[Fillable([
    'company_id', 'name', 'code', 'category', 'unit', 'accrual_method',
    'rate_hours', 'rate_bp', 'annual_cap_hours', 'annual_cap_cents',
    'carryover_max_hours', 'carryover_max_cents', 'paid',
    'expense_account_id', 'liability_account_id', 'is_default', 'is_active',
])]
class TimeOffPolicy extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * @return HasMany<EmployeeTimeOffPolicy, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeTimeOffPolicy::class);
    }

    public function isDollarUnit(): bool
    {
        return $this->unit === TimeOffUnit::Dollars;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => TimeOffCategory::class,
            'unit' => TimeOffUnit::class,
            'accrual_method' => TimeOffAccrualMethod::class,
            'rate_hours' => 'decimal:2',
            'rate_bp' => 'integer',
            'annual_cap_hours' => 'decimal:2',
            'annual_cap_cents' => 'integer',
            'carryover_max_hours' => 'decimal:2',
            'carryover_max_cents' => 'integer',
            'paid' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
