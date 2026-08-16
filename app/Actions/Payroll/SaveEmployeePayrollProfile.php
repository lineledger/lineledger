<?php

namespace App\Actions\Payroll;

use App\Enums\PayBasis;
use App\Enums\TimeOffAccrualMethod;
use App\Enums\TimeOffCategory;
use App\Enums\VacationPolicy;
use App\Models\EmployeeAccrualBalance;
use App\Models\EmployeePayrollProfile;
use App\Models\EmployeeTimeOffPolicy;
use App\Models\TimeOffPolicy;
use App\Support\Payroll\BankedOvertimeRules;
use App\Support\Payroll\PayrollItemType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates an employee's payroll profile and its recurring earning /
 * deduction templates. Shared by the Livewire setup UI and the API.
 *
 * The SIN is written through {@see EmployeePayrollProfile::setSin()} so it is
 * encrypted at rest and only the last four digits are kept in cleartext.
 *
 * Expected $data shape (cents-based):
 *   contact_id:                       int
 *   sin:                              ?string  (raw; only applied when present)
 *   date_of_birth, hire_date, termination_date: ?string (Y-m-d)
 *   province_of_employment:           string (2-letter; 'QC' enables QPP/QPIP/Quebec tax)
 *   pay_basis:                        string (PayBasis value)
 *   annual_salary_cents:              ?int
 *   hourly_rate_cents:                ?int
 *   default_hours_per_period:         ?string|float
 *   payroll_schedule_id:              ?int
 *   td1_federal_claim_cents:          int
 *   td1_federal_code:                 ?string
 *   td1_provincial_claim_cents:       int
 *   td1_provincial_code:              ?string
 *   cpp_exempt, ei_exempt, qpip_exempt: bool
 *   additional_tax_per_period_cents:  int
 *   vacation_policy:                  string (VacationPolicy value)
 *   vacation_rate_bp:                 int
 *   wage_expense_account_id:          ?int
 *   class_id, location_id:            ?int
 *   is_active:                        ?bool
 *   recurring_items: array<int, array{
 *     kind: string, code: string, name: string, calc_type: string,
 *     amount_cents: ?int, percent_bp: ?int, liability_account_id: ?int,
 *     expense_account_id: ?int, t4_box: ?string, reduces_taxable: ?bool
 *   }>
 */
final class SaveEmployeePayrollProfile
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?EmployeePayrollProfile $profile = null): EmployeePayrollProfile
    {
        return DB::transaction(function () use ($data, $profile): EmployeePayrollProfile {
            $payBasis = $data['pay_basis'] instanceof PayBasis
                ? $data['pay_basis']
                : PayBasis::from($data['pay_basis']);

            $vacationPolicy = $data['vacation_policy'] instanceof VacationPolicy
                ? $data['vacation_policy']
                : VacationPolicy::from($data['vacation_policy'] ?? VacationPolicy::Accrue->value);

            $attributes = [
                'contact_id' => $data['contact_id'],
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'hire_date' => $data['hire_date'] ?? null,
                'termination_date' => $data['termination_date'] ?? null,
                'province_of_employment' => $data['province_of_employment'],
                'pay_basis' => $payBasis,
                'annual_salary_cents' => $data['annual_salary_cents'] ?? null,
                'hourly_rate_cents' => $data['hourly_rate_cents'] ?? null,
                'default_hours_per_period' => $data['default_hours_per_period'] ?? null,
                'payroll_schedule_id' => $data['payroll_schedule_id'] ?? null,
                'td1_federal_claim_cents' => (int) ($data['td1_federal_claim_cents'] ?? 0),
                'td1_federal_code' => $data['td1_federal_code'] ?? null,
                'td1_provincial_claim_cents' => (int) ($data['td1_provincial_claim_cents'] ?? 0),
                'td1_provincial_code' => $data['td1_provincial_code'] ?? null,
                'cpp_exempt' => (bool) ($data['cpp_exempt'] ?? false),
                'ei_exempt' => (bool) ($data['ei_exempt'] ?? false),
                'qpip_exempt' => (bool) ($data['qpip_exempt'] ?? false),
                'income_tax_exempt' => (bool) ($data['income_tax_exempt'] ?? false),
                'workers_comp_exempt' => (bool) ($data['workers_comp_exempt'] ?? false),
                'workers_comp_rate_bp' => isset($data['workers_comp_rate_bp']) && $data['workers_comp_rate_bp'] !== ''
                    ? (int) $data['workers_comp_rate_bp']
                    : null,
                'cpt30_election_date' => $data['cpt30_election_date'] ?? null,
                'additional_tax_per_period_cents' => (int) ($data['additional_tax_per_period_cents'] ?? 0),
                'authorized_annual_deductions_cents' => (int) ($data['authorized_annual_deductions_cents'] ?? 0),
                'vacation_policy' => $vacationPolicy,
                'vacation_rate_bp' => (int) ($data['vacation_rate_bp'] ?? 400),
                'wage_expense_account_id' => $data['wage_expense_account_id'] ?? null,
                'class_id' => $data['class_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ];

            // Mid-year opening YTD balances are merged only when the caller sends
            // them, so an ordinary save never wipes seeded statutory openings.
            foreach ([
                'opening_pensionable_cents', 'opening_insurable_cents', 'opening_cpp_employee_cents',
                'opening_cpp2_employee_cents', 'opening_ei_employee_cents', 'opening_qpp_employee_cents',
                'opening_qpp2_employee_cents', 'opening_qpip_employee_cents', 'opening_qpip_insurable_cents',
            ] as $openingKey) {
                if (array_key_exists($openingKey, $data)) {
                    $attributes[$openingKey] = (int) $data[$openingKey];
                }
            }

            if (array_key_exists('opening_balances_as_of', $data)) {
                $attributes['opening_balances_as_of'] = $data['opening_balances_as_of'] ?: null;
            }

            // Banked-overtime fields merge only when sent (like the openings),
            // so a caller that doesn't know about banking never disables it.
            if (array_key_exists('banked_overtime_enabled', $data)) {
                $attributes['banked_overtime_enabled'] = (bool) $data['banked_overtime_enabled'];
            }

            if (array_key_exists('banked_overtime_agreement_date', $data)) {
                $attributes['banked_overtime_agreement_date'] = $data['banked_overtime_agreement_date'] ?: null;
            }

            if (array_key_exists('banked_overtime_multiplier_bp', $data)) {
                $attributes['banked_overtime_multiplier_bp'] = $data['banked_overtime_multiplier_bp'] !== '' && $data['banked_overtime_multiplier_bp'] !== null
                    ? (int) $data['banked_overtime_multiplier_bp']
                    : null;
            }

            // The designated time-off approver (step 1 of request approval) —
            // must be a member of THIS company, or leave details would mail to
            // an outsider.
            if (array_key_exists('approver_user_id', $data)) {
                $approverId = (int) ($data['approver_user_id'] ?: 0);

                if ($approverId > 0 && ! app('current_company')->members()->whereKey($approverId)->exists()) {
                    throw ValidationException::withMessages(['approver_user_id' => __('The approver must be a member of this company.')]);
                }

                $attributes['approver_user_id'] = $approverId ?: null;
            }

            $wasNew = ! ($profile && $profile->exists);

            if ($profile && $profile->exists) {
                $profile->fill($attributes);
            } else {
                $profile = new EmployeePayrollProfile($attributes);
            }

            // Banked overtime is gated by employment standards, validated on the
            // profile's FINAL state: blocked outright where the province has no
            // banking provision (NB), and requiring the written-agreement date
            // wherever one is mandated.
            if ($profile->banked_overtime_enabled) {
                $rules = BankedOvertimeRules::forProvince((string) $profile->province_of_employment);

                if (! $rules['allowed']) {
                    throw ValidationException::withMessages([
                        'banked_overtime_enabled' => __('Employment standards in :province do not permit banking overtime as time off.', ['province' => $profile->province_of_employment]),
                    ]);
                }

                if ($rules['agreement_required'] && $profile->banked_overtime_agreement_date === null) {
                    throw ValidationException::withMessages([
                        'banked_overtime_agreement_date' => __('Banking overtime requires a written agreement — record its date.'),
                    ]);
                }
            }

            if (array_key_exists('sin', $data)) {
                $profile->setSin($data['sin']);
            }

            $profile->save();

            if (array_key_exists('recurring_items', $data)) {
                $profile->recurringItems()->delete();

                foreach (array_values($data['recurring_items']) as $index => $item) {
                    // Per-item tax-treatment flags: take what the caller sends,
                    // else sensible defaults from the item's category + Type + code.
                    $flagDefaults = PayrollItemType::flagDefaults((string) $item['kind'], $item['type'] ?? null, (string) $item['code']);
                    $flags = [];
                    foreach ($flagDefaults as $flag => $default) {
                        $flags[$flag] = (bool) ($item[$flag] ?? $default);
                    }

                    // Back-compat: a caller sending the legacy `reduces_taxable`
                    // (and no explicit pre-tax flags) maps it onto pre-tax fed/prov.
                    if (! array_key_exists('pre_tax_federal', $item)
                        && ! array_key_exists('pre_tax_provincial', $item)
                        && array_key_exists('reduces_taxable', $item)) {
                        $flags['pre_tax_federal'] = (bool) $item['reduces_taxable'];
                        $flags['pre_tax_provincial'] = (bool) $item['reduces_taxable'];
                    }

                    $profile->recurringItems()->create(array_merge([
                        'kind' => $item['kind'],
                        'type' => $item['type'] ?? null,
                        'code' => $item['code'],
                        'name' => $item['name'],
                        'calc_type' => $item['calc_type'] ?? 'fixed',
                        'calc_basis' => $item['calc_basis'] ?? null,
                        'amount_cents' => $item['amount_cents'] ?? null,
                        'percent_bp' => $item['percent_bp'] ?? null,
                        'annual_maximum_cents' => $item['annual_maximum_cents'] ?? null,
                        'liability_account_id' => $item['liability_account_id'] ?? null,
                        'expense_account_id' => $item['expense_account_id'] ?? null,
                        't4_box' => $item['t4_box'] ?? null,
                        // Keep the legacy reduces_taxable column in step with the pre-tax flags.
                        'reduces_taxable' => $flags['pre_tax_federal'] || $flags['pre_tax_provincial'],
                        'is_active' => $item['is_active'] ?? true,
                        'line_order' => $index,
                    ], $flags));
                }
            }

            if (array_key_exists('time_off_policies', $data)) {
                $this->syncTimeOffPolicies($profile, $data['time_off_policies']);
            }

            // A brand-new enrolment gets every active default policy ("Use for
            // new employees") — after the explicit sync above so a caller that
            // deliberately sent assignments still gains the defaults, but a
            // later edit that removes one is respected (create-only).
            if ($wasNew) {
                foreach (TimeOffPolicy::query()->where('is_active', true)->where('is_default', true)->get() as $default) {
                    $profile->ensureTimeOffPolicyAssigned($default);
                }
            }

            if ($profile->banked_overtime_enabled) {
                $this->ensureBankedPolicy($profile);
            }

            return $profile->refresh();
        });
    }

    /**
     * Enabling banked overtime needs the 'banked' time-off policy to exist and
     * be assigned to this employee: the policy code is what lets a banked day
     * pay at the regular rate and draw the balance down, and what the pay-run
     * accrual rows key the {@see EmployeeAccrualBalance} ledger on. Manual
     * accrual method means the time-off cron never touches it — banked hours
     * move only through pay runs. Idempotent.
     */
    private function ensureBankedPolicy(EmployeePayrollProfile $profile): void
    {
        $policy = TimeOffPolicy::firstOrCreate(
            ['code' => 'banked'],
            [
                'name' => __('Banked time'),
                'category' => TimeOffCategory::Banked->value,
                'unit' => 'hours',
                'accrual_method' => TimeOffAccrualMethod::Manual->value,
                'rate_hours' => 0,
                'rate_bp' => 0,
                'paid' => true,
                'is_active' => true,
            ],
        );

        EmployeeTimeOffPolicy::firstOrCreate(
            ['employee_payroll_profile_id' => $profile->id, 'time_off_policy_id' => $policy->id],
            ['is_active' => true],
        );
    }

    /**
     * Reconcile an employee's time-off policy assignments and flow each
     * opening balance into the running {@see EmployeeAccrualBalance} — on the
     * policy's own SIDE of the row (hours for hour policies, dollars for
     * dollar policies; both sides share one row per code, e.g. a 'vacation'
     * hours policy rides beside the built-in dollar vacation-pay accrual):
     *
     *  - an untouched side (no balance, nothing accrued or used) takes the
     *    opening outright — covers new assignments AND rows the dollar accrual
     *    created first;
     *  - editing the opening later moves the balance by the DIFFERENCE, so
     *    accrued/used history is never clobbered and a typo fix lands.
     *
     * @param  array<int, array{time_off_policy_id: int|string, opening_balance?: int|float|string}>  $rows
     */
    private function syncTimeOffPolicies(EmployeePayrollProfile $profile, array $rows): void
    {
        $keep = [];

        foreach ($rows as $row) {
            $policyId = (int) $row['time_off_policy_id'];

            if ($policyId <= 0) {
                continue;
            }

            $policy = TimeOffPolicy::find($policyId);

            if ($policy === null) {
                continue;
            }

            $opening = (float) ($row['opening_balance'] ?? 0);
            $openingHours = $policy->isDollarUnit() ? 0 : $opening;
            $openingCents = $policy->isDollarUnit() ? (int) round($opening * 100) : 0;

            $previous = EmployeeTimeOffPolicy::where('employee_payroll_profile_id', $profile->id)
                ->where('time_off_policy_id', $policyId)
                ->first();

            $previousHours = (float) ($previous->opening_balance_hours ?? 0);
            $previousCents = (int) ($previous->opening_balance_cents ?? 0);

            $assignment = EmployeeTimeOffPolicy::updateOrCreate(
                ['employee_payroll_profile_id' => $profile->id, 'time_off_policy_id' => $policyId],
                ['opening_balance_hours' => $openingHours, 'opening_balance_cents' => $openingCents, 'is_active' => true],
            );

            $this->applyOpeningBalance($profile, $policy, $openingHours, $openingCents, $previousHours, $previousCents);

            $keep[] = $assignment->id;
        }

        // Deactivate (never delete) assignments the user removed: the row keeps
        // the opening balance already applied, so re-adding the policy later
        // resumes with a zero delta instead of double-applying the opening.
        EmployeeTimeOffPolicy::where('employee_payroll_profile_id', $profile->id)
            ->whereNotIn('id', $keep ?: [0])
            ->update(['is_active' => false]);
    }

    /**
     * Land an assignment's opening balance on the matching side of the
     * employee's running balance row (see {@see syncTimeOffPolicies}).
     */
    private function applyOpeningBalance(
        EmployeePayrollProfile $profile,
        TimeOffPolicy $policy,
        float $openingHours,
        int $openingCents,
        float $previousHours,
        int $previousCents,
    ): void {
        $balance = EmployeeAccrualBalance::firstOrNew(
            ['employee_payroll_profile_id' => $profile->id, 'code' => $policy->code],
            ['name' => $policy->name],
        );

        if ($policy->isDollarUnit()) {
            $untouched = (int) $balance->balance_cents === 0
                && (int) $balance->accrued_ytd_cents === 0
                && (int) $balance->used_ytd_cents === 0;

            if ($untouched && $openingCents > 0) {
                // Fresh side: the opening IS the balance (also self-heals an
                // opening an earlier save swallowed — nothing else moved it).
                $balance->balance_cents = $openingCents;
            } elseif (! $untouched && $openingCents !== $previousCents) {
                // Adjust by the edit's difference; accrued/used history stays put.
                $balance->balance_cents = (int) $balance->balance_cents + ($openingCents - $previousCents);
            } else {
                return;
            }
        } else {
            $untouched = (float) $balance->balance_hours === 0.0
                && (float) $balance->accrued_ytd_hours === 0.0
                && (float) $balance->used_ytd_hours === 0.0;

            if ($untouched && $openingHours > 0) {
                $balance->balance_hours = $openingHours;
            } elseif (! $untouched && $openingHours !== $previousHours) {
                $balance->balance_hours = (float) $balance->balance_hours + ($openingHours - $previousHours);
            } else {
                return;
            }
        }

        $balance->save();
    }
}
