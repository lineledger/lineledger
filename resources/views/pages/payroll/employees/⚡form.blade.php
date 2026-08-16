<?php

use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Actions\Payroll\TerminateEmployee;
use App\Actions\Portal\IssuePortalLoginToken;
use App\Notifications\Portal\EmployeePortalLoginLinkNotification;
use App\Enums\AccountSubtype;
use App\Enums\Country;
use App\Enums\PayBasis;
use App\Enums\RoeReason;
use App\Enums\VacationPolicy;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeeAccrualBalance;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\TimeOffPolicy;
use App\Rules\MoneyString;
use App\Support\Money;
use App\Support\Payroll\Constants\PayrollConstantsRepository;
use App\Support\Payroll\PayrollItemType;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Employee payroll setup')] class extends Component {
    public Company $company;

    public Contact $contact;

    public ?int $profileId = null;

    // Identity
    public string $sin = '';

    public ?string $sinLast4 = null;

    public string $date_of_birth = '';

    public string $hire_date = '';

    public string $termination_date = '';

    // Tax jurisdiction + pay
    public string $province_of_employment = 'ON';

    public string $pay_basis = 'salary';

    public string $annual_salary = '';

    public string $hourly_rate = '';

    public string $default_hours_per_period = '';

    public ?int $payroll_schedule_id = null;

    // TD1
    public string $td1_federal_claim = '';

    public string $td1_federal_code = '1';

    public string $td1_provincial_claim = '';

    public string $td1_provincial_code = '1';

    // Statutory
    public bool $cpp_exempt = false;

    public bool $ei_exempt = false;

    public bool $qpip_exempt = false;

    public bool $income_tax_exempt = false;

    public bool $workers_comp_exempt = false;

    public string $workers_comp_rate = '';

    public string $cpt30_election_date = '';

    public string $additional_tax_per_period = '0';

    public string $authorized_annual_deductions = '0';

    // Vacation
    public string $vacation_policy = 'accrue';

    public float $vacation_rate_pct = 4.0;

    // Banked overtime (time-off-in-lieu)
    public bool $banked_overtime_enabled = false;

    public string $banked_overtime_agreement_date = '';

    /** '' = the province default; otherwise basis points (10000 = 1.0×). */
    public string $banked_overtime_multiplier = '';

    /** The company member who approves this employee's time-off requests. */
    public ?int $approver_user_id = null;

    // GL
    public ?int $wage_expense_account_id = null;

    public bool $is_active = true;

    /** @var array<int, array<string, mixed>> Recurring earning / deduction templates applied to every pay run. */
    public array $recurring_items = [];

    /** @var array<int, array{time_off_policy_id: int|string|null, opening_balance: string}> Assigned time-off policies. */
    public array $time_off_policies = [];

    // Mid-year opening YTD (statutory accumulators carried in partway through the year)
    public bool $has_opening_balances = false;

    public string $opening_balances_as_of = '';

    public string $opening_pensionable = '0';

    public string $opening_insurable = '0';

    public string $opening_cpp_employee = '0';

    public string $opening_cpp2_employee = '0';

    public string $opening_ei_employee = '0';

    public string $opening_qpp_employee = '0';

    public string $opening_qpp2_employee = '0';

    public string $opening_qpip_employee = '0';

    public string $opening_qpip_insurable = '0';

    public function mount(Company $company, Contact $contact): void
    {
        $this->company = $company;

        abort_unless($company->usesPayroll(), 404);
        abort_unless($contact->is_employee, 404);

        $this->contact = $contact;

        $profile = $contact->payrollProfile;

        if ($profile) {
            $this->profileId = $profile->id;
            $this->sinLast4 = $profile->sin_last4;
            $this->date_of_birth = $profile->date_of_birth?->toDateString() ?? '';
            $this->hire_date = $profile->hire_date?->toDateString() ?? '';
            $this->termination_date = $profile->termination_date?->toDateString() ?? '';
            $this->province_of_employment = $profile->province_of_employment;
            $this->pay_basis = $profile->pay_basis->value;
            $this->annual_salary = $profile->annual_salary_cents !== null ? Money::fromCents($profile->annual_salary_cents)->toDecimalString() : '';
            $this->hourly_rate = $profile->hourly_rate_cents !== null ? Money::fromCents($profile->hourly_rate_cents)->toDecimalString() : '';
            $this->default_hours_per_period = $profile->default_hours_per_period !== null ? (string) $profile->default_hours_per_period : '';
            $this->payroll_schedule_id = $profile->payroll_schedule_id;
            $this->td1_federal_claim = Money::fromCents($profile->td1_federal_claim_cents)->toDecimalString();
            $this->td1_federal_code = $profile->td1_federal_code ?? '1';
            $this->td1_provincial_claim = Money::fromCents($profile->td1_provincial_claim_cents)->toDecimalString();
            $this->td1_provincial_code = $profile->td1_provincial_code ?? '1';
            $this->cpp_exempt = $profile->cpp_exempt;
            $this->ei_exempt = $profile->ei_exempt;
            $this->qpip_exempt = $profile->qpip_exempt;
            $this->additional_tax_per_period = Money::fromCents($profile->additional_tax_per_period_cents)->toDecimalString();
            $this->vacation_policy = $profile->vacation_policy->value;
            $this->vacation_rate_pct = $profile->vacation_rate_bp / 100;
            $this->banked_overtime_enabled = $profile->banked_overtime_enabled;
            $this->banked_overtime_agreement_date = $profile->banked_overtime_agreement_date?->toDateString() ?? '';
            $this->banked_overtime_multiplier = $profile->banked_overtime_multiplier_bp !== null ? (string) $profile->banked_overtime_multiplier_bp : '';
            $this->approver_user_id = $profile->approver_user_id;
            $this->wage_expense_account_id = $profile->wage_expense_account_id;
            $this->is_active = $profile->is_active;
            $this->income_tax_exempt = $profile->income_tax_exempt;
            $this->workers_comp_exempt = $profile->workers_comp_exempt;
            $this->workers_comp_rate = $profile->workers_comp_rate_bp !== null ? (string) ((int) $profile->workers_comp_rate_bp / 100) : '';
            $this->cpt30_election_date = $profile->cpt30_election_date?->toDateString() ?? '';
            $this->authorized_annual_deductions = Money::fromCents($profile->authorized_annual_deductions_cents)->toDecimalString();
            $this->has_opening_balances = $profile->opening_balances_as_of !== null;
            $this->opening_balances_as_of = $profile->opening_balances_as_of?->toDateString() ?? '';
            $this->opening_pensionable = Money::fromCents($profile->opening_pensionable_cents)->toDecimalString();
            $this->opening_insurable = Money::fromCents($profile->opening_insurable_cents)->toDecimalString();
            $this->opening_cpp_employee = Money::fromCents($profile->opening_cpp_employee_cents)->toDecimalString();
            $this->opening_cpp2_employee = Money::fromCents($profile->opening_cpp2_employee_cents)->toDecimalString();
            $this->opening_ei_employee = Money::fromCents($profile->opening_ei_employee_cents)->toDecimalString();
            $this->opening_qpp_employee = Money::fromCents($profile->opening_qpp_employee_cents)->toDecimalString();
            $this->opening_qpp2_employee = Money::fromCents($profile->opening_qpp2_employee_cents)->toDecimalString();
            $this->opening_qpip_employee = Money::fromCents($profile->opening_qpip_employee_cents)->toDecimalString();
            $this->opening_qpip_insurable = Money::fromCents($profile->opening_qpip_insurable_cents)->toDecimalString();
            $this->recurring_items = $profile->recurringItems->map(fn ($item) => [
                'kind' => $item->kind,
                'type' => $item->type,
                'code' => $item->code,
                'name' => $item->name,
                'calc_type' => $item->calc_type,
                'calc_basis' => $item->calc_basis,
                'amount' => $item->amount_cents !== null ? Money::fromCents($item->amount_cents)->toDecimalString() : '',
                'percent' => $item->percent_bp !== null ? (string) ($item->percent_bp / 100) : '',
                'annual_maximum' => $item->annual_maximum_cents !== null ? Money::fromCents($item->annual_maximum_cents)->toDecimalString() : '',
                'expense_account_id' => $item->expense_account_id,
                'liability_account_id' => $item->liability_account_id,
                't4_box' => $item->t4_box ?? '',
                'is_active' => (bool) $item->is_active,
                'taxable_federal' => (bool) $item->taxable_federal,
                'taxable_provincial' => (bool) $item->taxable_provincial,
                'cpp_qpp' => (bool) $item->cpp_qpp,
                'qpip' => (bool) $item->qpip,
                'ei_insurable_earnings' => (bool) $item->ei_insurable_earnings,
                'ei_insurable_hours' => (bool) $item->ei_insurable_hours,
                'wcb_eligible' => (bool) $item->wcb_eligible,
                'tax_as_bonus' => (bool) $item->tax_as_bonus,
                'primary_earnings' => (bool) $item->primary_earnings,
                'add_to_net_pay_only' => (bool) $item->add_to_net_pay_only,
                'subtract_from_salary' => (bool) $item->subtract_from_salary,
                'stat_holiday_eligible' => (bool) $item->stat_holiday_eligible,
                'stat_holiday_payout' => (bool) $item->stat_holiday_payout,
                'pre_tax_federal' => (bool) $item->pre_tax_federal,
                'pre_tax_provincial' => (bool) $item->pre_tax_provincial,
            ])->values()->all();

            $this->time_off_policies = $profile->timeOffPolicies()->with('policy')->get()->map(fn ($a) => [
                'time_off_policy_id' => $a->time_off_policy_id,
                'opening_balance' => $a->policy && $a->policy->isDollarUnit()
                    ? (string) ((int) $a->opening_balance_cents / 100)
                    : (string) (float) $a->opening_balance_hours,
            ])->values()->all();
        } else {
            $this->province_of_employment = $company->address_region ?: 'ON';
            $this->applyTd1Defaults(); // start a new employee at the current basic amounts
        }
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function provinceOptions(): array
    {
        // Quebec runs its parallel system (QPP/QPIP/Revenu Québec); supported since v2.
        return collect(Country::Canada->regions())->all();
    }

    #[Computed]
    public function schedules()
    {
        return PayrollSchedule::query()->where('is_active', true)->orderBy('name')->get();
    }

    /** Company members selectable as the time-off approver. */
    #[Computed]
    public function companyMembers()
    {
        return $this->company->members()->orderBy('name')->get();
    }

    #[Computed]
    public function expenseAccounts()
    {
        return Account::query()
            ->where('subtype', AccountSubtype::Expense->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    #[Computed]
    public function liabilityAccounts()
    {
        return Account::query()
            ->whereIn('subtype', [
                AccountSubtype::CurrentLiability->value,
                AccountSubtype::OtherLiability->value,
                AccountSubtype::LongTermLiability->value,
            ])
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    public function addRecurringItem(string $kind): void
    {
        $kind = in_array($kind, ['earning', 'deduction', 'contribution', 'accrual'], true) ? $kind : 'deduction';
        $type = $kind === 'accrual' ? null : (array_key_first(PayrollItemType::options($kind)) ?: null);

        $this->recurring_items[] = array_merge([
            'kind' => $kind,
            'type' => $type,
            'code' => '',
            'name' => '',
            'calc_type' => 'fixed',
            'calc_basis' => $kind === 'accrual' ? 'percent_of_earnings' : null,
            'amount' => '',
            'percent' => '',
            'annual_maximum' => '',
            'expense_account_id' => null,
            'liability_account_id' => null,
            't4_box' => '',
            'is_active' => true,
        ], PayrollItemType::flagDefaults($kind, $type, ''));
    }

    /**
     * When a row's Type changes, reseed its flags (and prefill the name) from the
     * catalogue defaults.
     */
    public function updatedRecurringItems(mixed $value, ?string $key = null): void
    {
        if ($key === null || ! preg_match('/^(\d+)\.type$/', $key, $m)) {
            return;
        }

        $i = (int) $m[1];
        $row = $this->recurring_items[$i] ?? null;

        if ($row === null) {
            return;
        }

        foreach (PayrollItemType::flagDefaults((string) $row['kind'], $value ?: null, (string) ($row['code'] ?? '')) as $flag => $default) {
            $this->recurring_items[$i][$flag] = $default;
        }

        $defaults = PayrollItemType::defaults((string) $row['kind'], (string) $value);
        if (array_key_exists('t4_box', $defaults)) {
            $this->recurring_items[$i]['t4_box'] = (string) ($defaults['t4_box'] ?? '');
        }

        if (($row['name'] ?? '') === '' && $value) {
            $this->recurring_items[$i]['name'] = PayrollItemType::options((string) $row['kind'])[$value] ?? '';
        }
    }

    public function removeRecurringItem(int $index): void
    {
        unset($this->recurring_items[$index]);
        $this->recurring_items = array_values($this->recurring_items);
    }

    public function addTimeOffPolicy(): void
    {
        $this->time_off_policies[] = ['time_off_policy_id' => null, 'opening_balance' => ''];
    }

    public function removeTimeOffPolicy(int $index): void
    {
        unset($this->time_off_policies[$index]);
        $this->time_off_policies = array_values($this->time_off_policies);
    }

    /** @return array<int, string> Active company time-off policies, id => name. */
    #[Computed]
    public function timeOffPolicyOptions(): array
    {
        return TimeOffPolicy::query()->where('is_active', true)->orderBy('name')->get()
            ->mapWithKeys(fn (TimeOffPolicy $p) => [$p->id => $p->name])->all();
    }

    /** Current running time-off balances for this employee. */
    #[Computed]
    public function timeOffBalances()
    {
        if (! $this->profileId) {
            return collect();
        }

        return EmployeeAccrualBalance::query()
            ->where('employee_payroll_profile_id', $this->profileId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Fill the TD1 claim fields with the current-year basic personal amounts for
     * the selected province (federal BPA max + provincial basic). Returns false
     * when no payroll table is loaded for that province/date.
     */
    private function applyTd1Defaults(): bool
    {
        try {
            $set = app(PayrollConstantsRepository::class)->resolve($this->province_of_employment, $this->company->currentDateTime());
            $this->td1_federal_claim = Money::fromCents($set->federalBpaMaxCents())->toDecimalString();
            $this->td1_provincial_claim = Money::fromCents($set->provincialBpaCents())->toDecimalString();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function prefillTd1(): void
    {
        $this->applyTd1Defaults()
            ? Flux::toast(variant: 'success', text: __('Filled with the current basic personal amounts.'))
            : Flux::toast(variant: 'danger', text: __('No payroll tables are loaded for that province and date.'));
    }

    public function save(SaveEmployeePayrollProfile $action): void
    {
        $validated = $this->validate([
            'province_of_employment' => ['required', 'in:'.implode(',', array_keys($this->provinceOptions))],
            'pay_basis' => ['required', 'in:salary,hourly,commission'],
            'annual_salary' => ['nullable', 'string', 'required_if:pay_basis,salary', new MoneyString],
            'hourly_rate' => ['nullable', 'string', 'required_if:pay_basis,hourly', new MoneyString],
            'default_hours_per_period' => ['nullable', 'numeric', 'min:0'],
            'payroll_schedule_id' => ['nullable', 'integer', 'exists:payroll_schedules,id'],
            'sin' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['nullable', 'date'],
            'hire_date' => ['nullable', 'date'],
            'termination_date' => ['nullable', 'date'],
            'td1_federal_claim' => ['required', 'string', new MoneyString],
            'td1_federal_code' => ['nullable', 'string', 'max:2'],
            'td1_provincial_claim' => ['required', 'string', new MoneyString],
            'td1_provincial_code' => ['nullable', 'string', 'max:2'],
            'additional_tax_per_period' => ['nullable', 'string', new MoneyString],
            'authorized_annual_deductions' => ['nullable', 'string', new MoneyString],
            'cpt30_election_date' => ['nullable', 'date'],
            'income_tax_exempt' => ['boolean'],
            'workers_comp_exempt' => ['boolean'],
            'workers_comp_rate' => ['nullable', 'numeric', 'min:0'],
            'vacation_policy' => ['required', 'in:accrue,pay_each_cheque'],
            'vacation_rate_pct' => ['numeric', 'min:0', 'max:100'],
            'banked_overtime_enabled' => ['boolean'],
            'banked_overtime_agreement_date' => ['nullable', 'date'],
            'banked_overtime_multiplier' => ['nullable', 'in:,10000,15000,20000'],
            'approver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'wage_expense_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'is_active' => ['boolean'],
            'has_opening_balances' => ['boolean'],
            'opening_balances_as_of' => ['nullable', 'date'],
            'opening_pensionable' => ['nullable', 'string', new MoneyString],
            'opening_insurable' => ['nullable', 'string', new MoneyString],
            'opening_cpp_employee' => ['nullable', 'string', new MoneyString],
            'opening_cpp2_employee' => ['nullable', 'string', new MoneyString],
            'opening_ei_employee' => ['nullable', 'string', new MoneyString],
            'opening_qpp_employee' => ['nullable', 'string', new MoneyString],
            'opening_qpp2_employee' => ['nullable', 'string', new MoneyString],
            'opening_qpip_employee' => ['nullable', 'string', new MoneyString],
            'opening_qpip_insurable' => ['nullable', 'string', new MoneyString],
            'recurring_items' => ['array'],
            'recurring_items.*.kind' => ['required', 'in:earning,deduction,contribution,accrual'],
            'recurring_items.*.type' => ['nullable', 'string', 'max:60'],
            'recurring_items.*.code' => ['required', 'string', 'max:40'],
            'recurring_items.*.name' => ['required', 'string', 'max:255'],
            'recurring_items.*.calc_type' => ['required', 'in:fixed,percent_of_gross'],
            'recurring_items.*.calc_basis' => ['nullable', 'string', 'max:30'],
            'recurring_items.*.amount' => ['nullable', 'string', new MoneyString],
            'recurring_items.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'recurring_items.*.annual_maximum' => ['nullable', 'string', new MoneyString],
            'recurring_items.*.expense_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'recurring_items.*.liability_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'recurring_items.*.t4_box' => ['nullable', 'string', 'max:10'],
            'recurring_items.*.is_active' => ['boolean'],
            'recurring_items.*.taxable_federal' => ['boolean'],
            'recurring_items.*.taxable_provincial' => ['boolean'],
            'recurring_items.*.cpp_qpp' => ['boolean'],
            'recurring_items.*.qpip' => ['boolean'],
            'recurring_items.*.ei_insurable_earnings' => ['boolean'],
            'recurring_items.*.ei_insurable_hours' => ['boolean'],
            'recurring_items.*.wcb_eligible' => ['boolean'],
            'recurring_items.*.tax_as_bonus' => ['boolean'],
            'recurring_items.*.primary_earnings' => ['boolean'],
            'recurring_items.*.add_to_net_pay_only' => ['boolean'],
            'recurring_items.*.subtract_from_salary' => ['boolean'],
            'recurring_items.*.stat_holiday_eligible' => ['boolean'],
            'recurring_items.*.stat_holiday_payout' => ['boolean'],
            'recurring_items.*.pre_tax_federal' => ['boolean'],
            'recurring_items.*.pre_tax_provincial' => ['boolean'],
            'time_off_policies' => ['array'],
            'time_off_policies.*.time_off_policy_id' => ['nullable', 'integer', 'exists:time_off_policies,id'],
            'time_off_policies.*.opening_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($this->has_opening_balances && ($validated['opening_balances_as_of'] ?? '') === '') {
            $this->addError('opening_balances_as_of', __('Enter the tax year these opening balances belong to.'));

            return;
        }

        $profile = $this->profileId ? $this->contact->payrollProfile : null;

        $data = [
            'contact_id' => $this->contact->id,
            'province_of_employment' => $validated['province_of_employment'],
            'pay_basis' => $validated['pay_basis'],
            'annual_salary_cents' => $validated['pay_basis'] === 'salary' ? Money::fromString($validated['annual_salary'])->cents : null,
            'hourly_rate_cents' => $validated['pay_basis'] === 'hourly' ? Money::fromString($validated['hourly_rate'])->cents : null,
            'default_hours_per_period' => $validated['default_hours_per_period'] !== null && $validated['default_hours_per_period'] !== '' ? (float) $validated['default_hours_per_period'] : null,
            'payroll_schedule_id' => $validated['payroll_schedule_id'] ?: null,
            'date_of_birth' => $validated['date_of_birth'] ?: null,
            'hire_date' => $validated['hire_date'] ?: null,
            'termination_date' => $validated['termination_date'] ?: null,
            'td1_federal_claim_cents' => Money::fromString($validated['td1_federal_claim'])->cents,
            'td1_federal_code' => $validated['td1_federal_code'] ?: null,
            'td1_provincial_claim_cents' => Money::fromString($validated['td1_provincial_claim'])->cents,
            'td1_provincial_code' => $validated['td1_provincial_code'] ?: null,
            'cpp_exempt' => $this->cpp_exempt,
            'ei_exempt' => $this->ei_exempt,
            'qpip_exempt' => $this->province_of_employment === 'QC' ? $this->qpip_exempt : false,
            'additional_tax_per_period_cents' => $validated['additional_tax_per_period'] ? Money::fromString($validated['additional_tax_per_period'])->cents : 0,
            'vacation_policy' => $validated['vacation_policy'],
            'vacation_rate_bp' => (int) round($validated['vacation_rate_pct'] * 100),
            'banked_overtime_enabled' => \App\Support\Payroll\BankedOvertimeRules::isAllowed($validated['province_of_employment']) && $this->banked_overtime_enabled,
            'banked_overtime_agreement_date' => $validated['banked_overtime_agreement_date'] ?: null,
            'banked_overtime_multiplier_bp' => $validated['banked_overtime_multiplier'] ?: null,
            'approver_user_id' => $validated['approver_user_id'] ?: null,
            'wage_expense_account_id' => $validated['wage_expense_account_id'] ?: null,
            'is_active' => $validated['is_active'],
            'income_tax_exempt' => $this->income_tax_exempt,
            'workers_comp_exempt' => $this->workers_comp_exempt,
            'workers_comp_rate_bp' => $this->workers_comp_rate !== '' ? (int) round((float) $this->workers_comp_rate * 100) : null,
            'cpt30_election_date' => $this->province_of_employment === 'QC' ? null : ($validated['cpt30_election_date'] ?: null),
            'authorized_annual_deductions_cents' => $validated['authorized_annual_deductions'] ? Money::fromString($validated['authorized_annual_deductions'])->cents : 0,
        ];

        // Mid-year opening YTD: persist the seeded accumulators when enabled,
        // otherwise clear them (null the year-guard so they stop applying).
        $openingCents = fn (?string $v) => ($v ?? '') !== '' ? Money::fromString($v)->cents : 0;

        if ($this->has_opening_balances) {
            $data['opening_balances_as_of'] = $validated['opening_balances_as_of'];
            $data['opening_pensionable_cents'] = $openingCents($validated['opening_pensionable'] ?? null);
            $data['opening_insurable_cents'] = $openingCents($validated['opening_insurable'] ?? null);
            $data['opening_cpp_employee_cents'] = $openingCents($validated['opening_cpp_employee'] ?? null);
            $data['opening_cpp2_employee_cents'] = $openingCents($validated['opening_cpp2_employee'] ?? null);
            $data['opening_ei_employee_cents'] = $openingCents($validated['opening_ei_employee'] ?? null);
            $data['opening_qpp_employee_cents'] = $openingCents($validated['opening_qpp_employee'] ?? null);
            $data['opening_qpp2_employee_cents'] = $openingCents($validated['opening_qpp2_employee'] ?? null);
            $data['opening_qpip_employee_cents'] = $openingCents($validated['opening_qpip_employee'] ?? null);
            $data['opening_qpip_insurable_cents'] = $openingCents($validated['opening_qpip_insurable'] ?? null);
        } else {
            $data['opening_balances_as_of'] = null;
            $data['opening_pensionable_cents'] = 0;
            $data['opening_insurable_cents'] = 0;
            $data['opening_cpp_employee_cents'] = 0;
            $data['opening_cpp2_employee_cents'] = 0;
            $data['opening_ei_employee_cents'] = 0;
            $data['opening_qpp_employee_cents'] = 0;
            $data['opening_qpp2_employee_cents'] = 0;
            $data['opening_qpip_employee_cents'] = 0;
            $data['opening_qpip_insurable_cents'] = 0;
        }

        // Recurring earning/deduction/benefit/accrual templates. Always sent (even
        // empty) so the action deletes rows the user removed.
        $flagKeys = [
            'taxable_federal', 'taxable_provincial', 'cpp_qpp', 'qpip', 'ei_insurable_earnings',
            'ei_insurable_hours', 'wcb_eligible', 'tax_as_bonus', 'primary_earnings', 'add_to_net_pay_only',
            'subtract_from_salary', 'stat_holiday_eligible', 'stat_holiday_payout', 'pre_tax_federal', 'pre_tax_provincial',
        ];

        $data['recurring_items'] = collect($this->recurring_items)->map(function (array $row) use ($flagKeys) {
            $isAccrual = $row['kind'] === 'accrual';
            $percentBasis = $isAccrual && in_array((string) ($row['calc_basis'] ?? ''), ['percent_of_earnings', 'percent_of_hours'], true);

            // Accruals carry their rate per calc_basis; other items per calc_type.
            $useAmount = $isAccrual ? ! $percentBasis : $row['calc_type'] === 'fixed';
            $usePercent = $isAccrual ? $percentBasis : $row['calc_type'] === 'percent_of_gross';

            $payload = [
                'kind' => $row['kind'],
                'type' => $row['type'] ?? null,
                'code' => $row['code'],
                'name' => $row['name'],
                'calc_type' => $row['calc_type'],
                'calc_basis' => $isAccrual ? ($row['calc_basis'] ?? null) : null,
                'amount_cents' => $useAmount && ($row['amount'] ?? '') !== '' ? Money::fromString($row['amount'])->cents : null,
                'percent_bp' => $usePercent && ($row['percent'] ?? '') !== '' ? (int) round((float) $row['percent'] * 100) : null,
                'annual_maximum_cents' => ($row['annual_maximum'] ?? '') !== '' ? Money::fromString($row['annual_maximum'])->cents : null,
                'expense_account_id' => in_array($row['kind'], ['earning', 'contribution', 'accrual'], true) ? ($row['expense_account_id'] ?: null) : null,
                'liability_account_id' => in_array($row['kind'], ['deduction', 'contribution', 'accrual'], true) ? ($row['liability_account_id'] ?: null) : null,
                't4_box' => ($row['t4_box'] ?? '') !== '' ? $row['t4_box'] : null,
                'is_active' => (bool) ($row['is_active'] ?? true),
            ];

            foreach ($flagKeys as $flag) {
                $payload[$flag] = (bool) ($row[$flag] ?? false);
            }

            return $payload;
        })->all();

        $data['time_off_policies'] = collect($this->time_off_policies)
            ->filter(fn (array $r) => ! empty($r['time_off_policy_id']))
            ->map(fn (array $r) => [
                'time_off_policy_id' => (int) $r['time_off_policy_id'],
                'opening_balance' => $r['opening_balance'] ?? 0,
            ])
            ->values()
            ->all();

        // Only overwrite the SIN when the user typed a new one.
        if ($this->sin !== '') {
            $data['sin'] = $this->sin;
        }

        $action->handle($data, $profile);

        Flux::toast(variant: 'success', text: __('Payroll setup saved.'));

        $this->redirectRoute('payroll.employees.index', $this->company, navigate: true);
    }

    // --- Termination / ROE wizard ---------------------------------------------
    public string $f_term_last_day = '';

    public string $f_term_reason = 'A';

    public function openTerminate(): void
    {
        $this->f_term_last_day = $this->company->currentDateTime()->toDateString();
        $this->f_term_reason = 'A';

        Flux::modal('terminate-employee')->show();
    }

    public function terminate(TerminateEmployee $action): void
    {
        abort_if($this->profileId === null, 404);

        $validated = $this->validate([
            'f_term_last_day' => ['required', 'date'],
            'f_term_reason' => ['required', 'in:'.implode(',', array_column(RoeReason::cases(), 'value'))],
        ]);

        $action->handle(EmployeePayrollProfile::findOrFail($this->profileId), $validated['f_term_last_day']);

        // Deep-link to the ROE page, prefilled for this employee + reason + last day.
        $this->redirectRoute('payroll.reports.roe', [
            'company' => $this->company,
            'contact' => $this->contact->id,
            'reason' => $validated['f_term_reason'],
            'last' => $validated['f_term_last_day'],
        ], navigate: true);
    }

    /**
     * Email the employee a one-time magic link to the self-service ("my-pay")
     * portal. Reuses the shared token issuer; the link grants pay-document access
     * only — never banking. No-ops with a warning if the employee isn't eligible
     * (needs an email + an active profile).
     */
    public function sendPortalInvite(IssuePortalLoginToken $tokens): void
    {
        $eligible = Contact::query()
            ->whereKey($this->contact->id)
            ->employeePortalEligible()
            ->exists();

        if (! $eligible) {
            Flux::toast(__('Add an email address and save the profile before inviting this employee.'), variant: 'warning');

            return;
        }

        $url = $tokens->handle($this->company, $this->contact, null, 'employee-portal.login.consume');
        $this->contact->notify(new EmployeePortalLoginLinkNotification($url, $this->company, IssuePortalLoginToken::TTL_MINUTES));

        Flux::toast(__('Portal invite sent to :email.', ['email' => $this->contact->email]), variant: 'success');
    }
}; ?>

<section class="mx-auto w-full max-w-3xl">
    <div class="mb-6">
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('payroll.employees.index')" wire:navigate>
            {{ __('Back to employee setup') }}
        </flux:button>
        <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="xl" level="1">{{ $contact->display_name }}</flux:heading>
                <flux:subheading>{{ __('Payroll profile') }}</flux:subheading>
            </div>
            @if ($profileId)
                <div class="flex flex-wrap items-center gap-2">
                    <flux:button variant="ghost" size="sm" icon="envelope" wire:click="sendPortalInvite" data-test="send-portal-invite">
                        {{ __('Send portal invite') }}
                    </flux:button>
                    <flux:button variant="ghost" size="sm" icon="user-minus" wire:click="openTerminate" data-test="terminate-employee">
                        {{ __('Terminate & issue ROE') }}
                    </flux:button>
                </div>
            @endif
        </div>
    </div>

    <form wire:submit="save" class="space-y-8">
        {{-- Identity --}}
        <div class="rounded-lg border border-border p-5">
            <flux:heading size="lg" class="mb-4">{{ __('Identity') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="sin" :label="__('Social Insurance Number')" :placeholder="$sinLast4 ? '•••• •'.$sinLast4 : '000 000 000'" :description="$sinLast4 ? __('Leave blank to keep the current SIN.') : null" />
                <flux:input type="date" wire:model="date_of_birth" :label="__('Date of birth')" />
                <flux:input type="date" wire:model="hire_date" :label="__('Hire date')" />
                <flux:input type="date" wire:model="termination_date" :label="__('Termination date')" :description="__('Set when the employee leaves (used for ROE).')" />
            </div>
        </div>

        {{-- Pay --}}
        <div class="rounded-lg border border-border p-5">
            <flux:heading size="lg" class="mb-4">{{ __('Pay') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:select wire:model.live="province_of_employment" :label="__('Province of employment')" :description="$province_of_employment === 'QC' ? __('Quebec uses QPP, QPIP and Revenu Québec tax.') : null">
                    @foreach ($this->provinceOptions as $code => $name)
                        <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="payroll_schedule_id" :label="__('Pay schedule')">
                    <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                    @foreach ($this->schedules as $schedule)
                        <flux:select.option value="{{ $schedule->id }}">{{ $schedule->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:radio.group wire:model.live="pay_basis" :label="__('Pay basis')" variant="segmented" class="md:col-span-2">
                    <flux:radio value="salary" :label="__('Salary')" />
                    <flux:radio value="hourly" :label="__('Hourly')" />
                    <flux:radio value="commission" :label="__('Commission')" />
                </flux:radio.group>

                @if ($pay_basis === 'salary')
                    <flux:input wire:model="annual_salary" :label="__('Annual salary')" inputmode="decimal" />
                @elseif ($pay_basis === 'hourly')
                    <flux:input wire:model="hourly_rate" :label="__('Hourly rate')" inputmode="decimal" />
                    <flux:input wire:model="default_hours_per_period" :label="__('Default hours per period')" inputmode="decimal" />
                @else
                    <flux:text class="text-sm text-muted-foreground md:col-span-2">{{ __('Commission-only — pay comes from recurring or run-time commission earnings.') }}</flux:text>
                @endif
            </div>
        </div>

        {{-- Tax / TD1 --}}
        <div class="rounded-lg border border-border p-5">
            <flux:heading size="lg" class="mb-1">{{ __('Tax credits (TD1)') }}</flux:heading>
            <flux:text class="mb-4 text-sm text-muted-foreground">{{ __('Total claim amounts from the employee’s federal and provincial TD1 forms.') }}</flux:text>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="td1_federal_claim" :label="__('Federal claim amount')" inputmode="decimal" />
                <flux:input wire:model="td1_provincial_claim" :label="$province_of_employment === 'QC' ? __('Quebec source-deductions claim (TP-1015.3)') : __('Provincial claim amount')" inputmode="decimal" />
                <flux:input wire:model="additional_tax_per_period" :label="__('Additional tax per pay')" inputmode="decimal" />
                <flux:input wire:model="authorized_annual_deductions" :label="__('Authorized annual deductions')" inputmode="decimal" :description="__('CRA/RQ-approved annual deductions (e.g. T1213).')" />
                @if ($province_of_employment !== 'QC')
                    <flux:input type="date" wire:model="cpt30_election_date" :label="__('CPT30 election date')" :description="__('Set if the employee (65–70) filed CPT30 to stop CPP.')" />
                @endif
            </div>
            <flux:button type="button" variant="ghost" size="sm" icon="sparkles" wire:click="prefillTd1" class="mt-3">{{ __('Use current basic amounts') }}</flux:button>
            <div class="mt-4 flex flex-wrap gap-6">
                <flux:switch wire:model="cpp_exempt" :label="$province_of_employment === 'QC' ? __('QPP exempt') : __('CPP exempt')" />
                <flux:switch wire:model="ei_exempt" :label="__('EI exempt')" />
                @if ($province_of_employment === 'QC')
                    <flux:switch wire:model="qpip_exempt" :label="__('QPIP exempt')" />
                @endif
                <flux:switch wire:model="income_tax_exempt" :label="__('Income tax exempt')" :description="__('No income tax withheld; CPP/EI still apply.')" />
            </div>

            @if ($province_of_employment !== 'QC')
                <div class="mt-4 flex flex-wrap items-end gap-6">
                    <flux:switch wire:model="workers_comp_exempt" :label="__('Workers’ comp exempt')" :description="__('Excluded from WSIB/WCB assessment.')" />
                    <flux:input type="number" step="0.01" wire:model="workers_comp_rate" :label="__('WC rate override / $100')" inputmode="decimal" class="max-w-[180px]" :description="__('Optional — rate group; blank uses the province rate.')" />
                </div>
            @endif
        </div>

        {{-- Vacation + GL --}}
        <div class="rounded-lg border border-border p-5">
            <flux:heading size="lg" class="mb-4">{{ __('Vacation & posting') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:select wire:model="vacation_policy" :label="__('Vacation policy')">
                    <flux:select.option value="accrue">{{ __('Accrue to liability') }}</flux:select.option>
                    <flux:select.option value="pay_each_cheque">{{ __('Pay on every cheque') }}</flux:select.option>
                </flux:select>
                <flux:input type="number" step="0.5" wire:model="vacation_rate_pct" :label="__('Vacation rate (%)')" />

                <flux:select wire:model="approver_user_id" :label="__('Time-off approver')" :description="__('Who approves this employee’s time-off requests first. Blank = any payroll user.')" data-test="approver-select">
                    <flux:select.option value="">{{ __('— any payroll user —') }}</flux:select.option>
                    @foreach ($this->companyMembers as $member)
                        <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="wage_expense_account_id" :label="__('Wage expense account')" :description="__('Defaults to Wages & Salaries Expense.')">
                    <flux:select.option value="">{{ __('— default (Wages & Salaries Expense) —') }}</flux:select.option>
                    @foreach ($this->expenseAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="mt-4">
                <flux:switch wire:model="is_active" :label="__('Active for payroll')" />
            </div>
        </div>

        {{-- Banked overtime (time-off-in-lieu) --}}
        @php($bankedRules = \App\Support\Payroll\BankedOvertimeRules::forProvince($province_of_employment))
        <div class="rounded-lg border border-border p-5" data-test="banked-overtime-card">
            <flux:heading size="lg" class="mb-1">{{ __('Banked overtime') }}</flux:heading>
            <flux:text class="mb-4 text-sm text-muted-foreground">{{ __('Let this employee bank overtime as paid time off instead of being paid out. Banked hours accrue at the province’s rate and are paid at the regular rate when taken.') }}</flux:text>

            @if (! $bankedRules['allowed'])
                <flux:callout variant="warning" data-test="banked-not-allowed">
                    {{ __('Employment standards in :province do not permit banking overtime as time off.', ['province' => $province_of_employment]) }}
                </flux:callout>
            @else
                <div class="space-y-4">
                    <flux:switch wire:model.live="banked_overtime_enabled" :label="__('Allow banking overtime')" data-test="banked-toggle" />

                    @if ($banked_overtime_enabled)
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <flux:input
                                type="date"
                                wire:model="banked_overtime_agreement_date"
                                :label="__('Written agreement date')"
                                :description="__('Employment standards require a written agreement to bank overtime.')"
                                data-test="banked-agreement-date"
                            />
                            <flux:select wire:model="banked_overtime_multiplier" :label="__('Bank rate')" :description="__('How many hours land in the bank per overtime hour worked.')">
                                <flux:select.option value="">{{ __(':province default (:rate×)', ['province' => $province_of_employment, 'rate' => number_format($bankedRules['multiplier_bp'] / 10000, 1)]) }}</flux:select.option>
                                <flux:select.option value="10000">{{ __('1.0× (straight time)') }}</flux:select.option>
                                <flux:select.option value="15000">{{ __('1.5×') }}</flux:select.option>
                                <flux:select.option value="20000">{{ __('2.0×') }}</flux:select.option>
                            </flux:select>
                        </div>
                        @if ($bankedRules['payout_deadline_days'] !== null)
                            <flux:text class="text-xs text-muted-foreground">
                                {{ __('In :province, banked time should be taken or paid out within :days days.', ['province' => $province_of_employment, 'days' => $bankedRules['payout_deadline_days']]) }}
                            </flux:text>
                        @endif
                    @endif
                </div>
            @endif
        </div>

        {{-- Recurring earnings, deductions, benefits & accruals --}}
        <div class="rounded-lg border border-border p-5">
            <flux:heading size="lg" class="mb-1">{{ __('Earnings, deductions, benefits & accruals') }}</flux:heading>
            <flux:text class="mb-4 text-sm text-muted-foreground">{{ __('Standing items applied to every pay run. Each has a Type and per-item tax-treatment flags, posts to the GL, and (if tagged) flows to the right T4 box.') }}</flux:text>

            @error('recurring_items') <flux:callout variant="danger" class="mb-4">{{ $message }}</flux:callout> @enderror

            <div class="space-y-4">
                @forelse ($recurring_items as $i => $row)
                    @php($kind = $row['kind'])
                    <div wire:key="ri-{{ $i }}" class="rounded-lg border border-border bg-muted/30 p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <flux:badge size="sm" :color="['earning' => 'emerald', 'deduction' => 'amber', 'contribution' => 'sky', 'accrual' => 'violet'][$kind] ?? 'zinc'">
                                {{ ['earning' => __('Earning'), 'deduction' => __('Deduction'), 'contribution' => __('Benefit'), 'accrual' => __('Accrual')][$kind] ?? $kind }}
                            </flux:badge>
                            <flux:button variant="ghost" size="sm" icon="trash" wire:click="removeRecurringItem({{ $i }})" :aria-label="__('Remove')" />
                        </div>

                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <flux:input wire:model="recurring_items.{{ $i }}.name" :label="__('Name')" />
                            <flux:input wire:model="recurring_items.{{ $i }}.code" :label="__('Code')" />

                            @if ($kind === 'accrual')
                                <flux:select wire:model.live="recurring_items.{{ $i }}.calc_basis" :label="__('Calculation basis')">
                                    @foreach (\App\Support\Payroll\PayrollItemType::calcBases() as $slug => $label)
                                        <flux:select.option value="{{ $slug }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>

                                @if (in_array($row['calc_basis'] ?? '', ['percent_of_earnings', 'percent_of_hours']))
                                    <flux:input type="number" step="0.01" wire:model="recurring_items.{{ $i }}.percent" :label="__('Percent')" inputmode="decimal" />
                                @else
                                    <flux:input wire:model="recurring_items.{{ $i }}.amount" :label="in_array($row['calc_basis'] ?? '', ['hours', 'units', 'miles']) ? __('Quantity per pay') : __('Amount per pay')" inputmode="decimal" />
                                @endif
                            @else
                                <flux:select wire:model.live="recurring_items.{{ $i }}.type" :label="__('Type')">
                                    <flux:select.option value="">{{ __('— select —') }}</flux:select.option>
                                    @foreach (\App\Support\Payroll\PayrollItemType::options($kind) as $slug => $label)
                                        <flux:select.option value="{{ $slug }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>

                                <flux:radio.group wire:model.live="recurring_items.{{ $i }}.calc_type" :label="__('Calculation')" variant="segmented">
                                    <flux:radio value="fixed" :label="__('Fixed $')" />
                                    <flux:radio value="percent_of_gross" :label="__('% of gross')" />
                                </flux:radio.group>

                                @if (($row['calc_type'] ?? 'fixed') === 'fixed')
                                    <flux:input wire:model="recurring_items.{{ $i }}.amount" :label="$kind === 'contribution' ? __('Employer amount per pay') : __('Amount per pay')" inputmode="decimal" />
                                @else
                                    <flux:input type="number" step="0.01" wire:model="recurring_items.{{ $i }}.percent" :label="__('Percent of gross')" inputmode="decimal" />
                                @endif
                            @endif

                            @if (in_array($kind, ['earning', 'contribution', 'accrual']))
                                <flux:select wire:model="recurring_items.{{ $i }}.expense_account_id" :label="__('Expense account')">
                                    <flux:select.option value="">{{ __('— default —') }}</flux:select.option>
                                    @foreach ($this->expenseAccounts as $account)
                                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif

                            @if (in_array($kind, ['deduction', 'contribution', 'accrual']))
                                <flux:select wire:model="recurring_items.{{ $i }}.liability_account_id" :label="__('Liability account')">
                                    <flux:select.option value="">{{ __('— default —') }}</flux:select.option>
                                    @foreach ($this->liabilityAccounts as $account)
                                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif

                            @if (in_array($kind, ['deduction', 'contribution']))
                                <flux:input wire:model="recurring_items.{{ $i }}.annual_maximum" :label="__('Annual maximum (optional)')" inputmode="decimal" :description="__('Stops once the yearly total is reached.')" />
                            @endif

                            @if (in_array($kind, ['earning', 'deduction', 'contribution']))
                                <flux:input wire:model="recurring_items.{{ $i }}.t4_box" :label="__('T4 box (optional)')" :placeholder="__('e.g. 20, 40')" />
                            @endif
                        </div>

                        @if (in_array($kind, ['earning', 'deduction', 'contribution']))
                            <div class="mt-3 rounded-md border border-border/60 p-3">
                                <flux:text class="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ __('Tax treatment') }}</flux:text>
                                <div class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                                    @if ($kind === 'deduction')
                                        <flux:checkbox wire:model="recurring_items.{{ $i }}.pre_tax_federal" :label="__('Pre-tax — Federal')" />
                                        <flux:checkbox wire:model="recurring_items.{{ $i }}.pre_tax_provincial" :label="__('Pre-tax — Provincial')" />
                                    @else
                                        <flux:checkbox wire:model="recurring_items.{{ $i }}.taxable_federal" :label="__('Taxable — Federal')" />
                                        <flux:checkbox wire:model="recurring_items.{{ $i }}.taxable_provincial" :label="__('Taxable — Provincial')" />
                                        <flux:checkbox wire:model="recurring_items.{{ $i }}.cpp_qpp" :label="__('CPP/QPP')" />
                                        <flux:checkbox wire:model="recurring_items.{{ $i }}.qpip" :label="__('PPIP (QPIP)')" />
                                        <flux:checkbox wire:model="recurring_items.{{ $i }}.ei_insurable_earnings" :label="__('EI insurable — earnings')" />
                                        <flux:checkbox wire:model="recurring_items.{{ $i }}.wcb_eligible" :label="__('WCB eligible')" />
                                        @if ($kind === 'earning')
                                            <flux:checkbox wire:model="recurring_items.{{ $i }}.ei_insurable_hours" :label="__('EI insurable — hours')" />
                                            <flux:checkbox wire:model="recurring_items.{{ $i }}.add_to_net_pay_only" :label="__('Add to net pay only')" />
                                            <flux:checkbox wire:model="recurring_items.{{ $i }}.tax_as_bonus" :label="__('Tax as bonus')" />
                                            <flux:checkbox wire:model="recurring_items.{{ $i }}.primary_earnings" :label="__('Primary earnings')" />
                                            <flux:checkbox wire:model="recurring_items.{{ $i }}.subtract_from_salary" :label="__('Subtract from salary')" />
                                            <flux:checkbox wire:model="recurring_items.{{ $i }}.stat_holiday_eligible" :label="__('Stat holiday eligible')" />
                                            <flux:checkbox wire:model="recurring_items.{{ $i }}.stat_holiday_payout" :label="__('Stat holiday payout')" />
                                        @endif
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div class="mt-3">
                            <flux:switch wire:model="recurring_items.{{ $i }}.is_active" :label="__('Active')" />
                        </div>
                    </div>
                @empty
                    <flux:text class="text-sm text-muted-foreground">{{ __('No items. A paycheque will be regular pay plus vacation only.') }}</flux:text>
                @endforelse
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <flux:button variant="filled" size="sm" icon="plus" wire:click="addRecurringItem('earning')">{{ __('Add earning') }}</flux:button>
                <flux:button variant="filled" size="sm" icon="plus" wire:click="addRecurringItem('deduction')">{{ __('Add deduction') }}</flux:button>
                <flux:button variant="filled" size="sm" icon="plus" wire:click="addRecurringItem('contribution')">{{ __('Add benefit') }}</flux:button>
                <flux:button variant="filled" size="sm" icon="plus" wire:click="addRecurringItem('accrual')">{{ __('Add accrual') }}</flux:button>
            </div>
        </div>

        {{-- Time-off policies + balances --}}
        <div class="rounded-lg border border-border p-5">
            <flux:heading size="lg" class="mb-1">{{ __('Time off') }}</flux:heading>
            <flux:text class="mb-4 text-sm text-muted-foreground">{{ __('Assign vacation/sick/personal policies. Balances accrue on each pay run (or by the nightly grant) and draw down when time off is recorded on a cheque.') }}</flux:text>

            @if (empty($this->timeOffPolicyOptions))
                <flux:callout variant="secondary" class="mb-4">{{ __('No active time-off policies yet. Create one under Payroll → Time-off policies.') }}</flux:callout>
            @endif

            <div class="space-y-2">
                @foreach ($time_off_policies as $i => $row)
                    <div class="flex flex-wrap items-end gap-2" wire:key="top-{{ $i }}">
                        <flux:select wire:model="time_off_policies.{{ $i }}.time_off_policy_id" :label="__('Policy')" class="min-w-[220px]">
                            <flux:select.option value="">{{ __('Select a policy…') }}</flux:select.option>
                            @foreach ($this->timeOffPolicyOptions as $id => $name)
                                <flux:select.option value="{{ $id }}">{{ $name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="time_off_policies.{{ $i }}.opening_balance" :label="__('Opening balance')" inputmode="decimal" class="w-36" :description="__('Hours (or $)')" />
                        <flux:button variant="ghost" size="sm" icon="trash" wire:click="removeTimeOffPolicy({{ $i }})" :aria-label="__('Remove')" />
                    </div>
                @endforeach
            </div>

            <flux:button variant="filled" size="sm" icon="plus" wire:click="addTimeOffPolicy" class="mt-3" :disabled="empty($this->timeOffPolicyOptions)">{{ __('Assign policy') }}</flux:button>

            @if ($this->timeOffBalances->isNotEmpty())
                <flux:separator class="my-4" variant="subtle" />
                <flux:heading size="sm" class="mb-2">{{ __('Current balances') }}</flux:heading>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted">
                            <tr>
                                <th class="px-3 py-2 text-left">{{ __('Type') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Balance') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Accrued YTD') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Used YTD') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($this->timeOffBalances as $balance)
                                @php($isDollar = (int) $balance->balance_cents !== 0 || (int) $balance->accrued_ytd_cents !== 0 || $balance->code === 'vacation')
                                <tr>
                                    <td class="px-3 py-2 font-medium">{{ $balance->name }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ $isDollar ? \App\Support\Money::fromCents((int) $balance->balance_cents)->format() : number_format((float) $balance->balance_hours, 2).' '.__('h') }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ $isDollar ? \App\Support\Money::fromCents((int) $balance->accrued_ytd_cents)->format() : number_format((float) $balance->accrued_ytd_hours, 2).' '.__('h') }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ $isDollar ? \App\Support\Money::fromCents((int) $balance->used_ytd_cents)->format() : number_format((float) $balance->used_ytd_hours, 2).' '.__('h') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Mid-year opening YTD --}}
        <div class="rounded-lg border border-border p-5">
            <flux:heading size="lg" class="mb-1">{{ __('Year-to-date opening balances') }}</flux:heading>
            <flux:text class="mb-4 text-sm text-muted-foreground">{{ __('For an employee onboarded partway through the year, enter the statutory amounts already withheld (by you or a prior provider) so the annual CPP/EI/QPP/QPIP maximums stay correct.') }}</flux:text>

            <flux:switch wire:model.live="has_opening_balances" :label="__('This employee has prior year-to-date payroll this year')" />

            @if ($has_opening_balances)
                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:input type="date" wire:model="opening_balances_as_of" :label="__('Balances as of')" :description="__('The tax year these amounts belong to.')" />
                    <flux:input wire:model="opening_pensionable" :label="__('Pensionable earnings to date')" inputmode="decimal" />
                    <flux:input wire:model="opening_insurable" :label="__('Insurable earnings to date')" inputmode="decimal" />
                    <flux:input wire:model="opening_ei_employee" :label="__('EI premiums withheld to date')" inputmode="decimal" />
                    @if ($province_of_employment === 'QC')
                        <flux:input wire:model="opening_qpp_employee" :label="__('QPP withheld to date')" inputmode="decimal" />
                        <flux:input wire:model="opening_qpp2_employee" :label="__('QPP2 withheld to date')" inputmode="decimal" />
                        <flux:input wire:model="opening_qpip_insurable" :label="__('QPIP insurable to date')" inputmode="decimal" />
                        <flux:input wire:model="opening_qpip_employee" :label="__('QPIP premiums withheld to date')" inputmode="decimal" />
                    @else
                        <flux:input wire:model="opening_cpp_employee" :label="__('CPP withheld to date')" inputmode="decimal" />
                        <flux:input wire:model="opening_cpp2_employee" :label="__('CPP2 withheld to date')" inputmode="decimal" />
                    @endif
                </div>
            @endif
        </div>

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="route('payroll.employees.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="payroll-profile-save">{{ __('Save payroll setup') }}</flux:button>
        </div>
    </form>

    {{-- Terminate / issue ROE --}}
    <flux:modal name="terminate-employee" class="max-w-md">
        <form wire:submit="terminate" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Terminate employee') }}</flux:heading>
                <flux:subheading>{{ __('Stamps the termination date, deactivates the profile, and opens a prefilled ROE.') }}</flux:subheading>
            </div>

            <flux:input type="date" wire:model="f_term_last_day" :label="__('Last day for which paid (Block 11)')" required />

            <flux:select wire:model="f_term_reason" :label="__('Reason for issuing (Block 16)')">
                @foreach (RoeReason::cases() as $r)
                    <flux:select.option value="{{ $r->value }}">{{ $r->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="terminate-confirm">{{ __('Terminate & open ROE') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
