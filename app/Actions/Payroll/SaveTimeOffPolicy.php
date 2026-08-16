<?php

namespace App\Actions\Payroll;

use App\Enums\TimeOffAccrualMethod;
use App\Enums\TimeOffCategory;
use App\Enums\TimeOffUnit;
use App\Models\TimeOffPolicy;
use App\Support\Payroll\EarningTypeCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a company time-off policy. The Livewire UI resolves the
 * human rate/cap inputs into the stored hours/basis-point/cents fields before
 * calling; this action just persists. Shared by the UI, the API and the seeder.
 *
 * Expected $data shape:
 *   name, code: string                   (code auto-derived from name when blank)
 *   category:        string (TimeOffCategory value)
 *   unit:            string (TimeOffUnit value)
 *   accrual_method:  string (TimeOffAccrualMethod value)
 *   rate_hours:      ?float, rate_bp: ?int
 *   annual_cap_hours, carryover_max_hours: ?float
 *   annual_cap_cents, carryover_max_cents: ?int
 *   paid, is_default, is_active: ?bool
 *   expense_account_id, liability_account_id: ?int
 */
final class SaveTimeOffPolicy
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?TimeOffPolicy $policy = null): TimeOffPolicy
    {
        return DB::transaction(function () use ($data, $policy): TimeOffPolicy {
            $code = $data['code'] ?? null;

            if ($code === null || $code === '') {
                $code = Str::slug((string) ($data['name'] ?? 'policy'), '_') ?: 'policy';
            }

            // The engine's wage codes (regular, overtime, stat_holiday, bonus, …)
            // are routing keys — a policy named "Overtime" would silently hijack
            // how those earnings price. 'vacation'/'sick' stay usable: leave
            // types are exactly what policies are for.
            if (array_key_exists($code, EarningTypeCatalogue::all()) && ! in_array($code, ['sick', 'vacation_pay'], true)) {
                throw ValidationException::withMessages([
                    'code' => __('The code ":code" is reserved by the payroll engine — pick another (e.g. add a suffix).', ['code' => $code]),
                ]);
            }

            $attributes = [
                'name' => $data['name'],
                'code' => $code,
                'category' => $data['category'] instanceof TimeOffCategory ? $data['category'] : TimeOffCategory::from($data['category']),
                'unit' => $data['unit'] instanceof TimeOffUnit ? $data['unit'] : TimeOffUnit::from($data['unit']),
                'accrual_method' => $data['accrual_method'] instanceof TimeOffAccrualMethod ? $data['accrual_method'] : TimeOffAccrualMethod::from($data['accrual_method']),
                'rate_hours' => (float) ($data['rate_hours'] ?? 0),
                'rate_bp' => (int) ($data['rate_bp'] ?? 0),
                'annual_cap_hours' => $data['annual_cap_hours'] ?? null,
                'annual_cap_cents' => $data['annual_cap_cents'] ?? null,
                'carryover_max_hours' => $data['carryover_max_hours'] ?? null,
                'carryover_max_cents' => $data['carryover_max_cents'] ?? null,
                'paid' => $data['paid'] ?? true,
                'expense_account_id' => $data['expense_account_id'] ?? null,
                'liability_account_id' => $data['liability_account_id'] ?? null,
                'is_default' => $data['is_default'] ?? false,
                'is_active' => $data['is_active'] ?? true,
            ];

            if ($policy && $policy->exists) {
                $policy->update($attributes);
            } else {
                $policy = TimeOffPolicy::create($attributes);
            }

            return $policy;
        });
    }
}
