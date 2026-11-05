<?php

use App\Actions\Payroll\SaveWorkersCompSettings;
use App\Enums\Activity;
use App\Enums\Country;
use App\Enums\DentalBenefitsCode;
use App\Enums\PermissionAction;
use App\Enums\RemittanceFrequency;
use App\Livewire\Attributes\Requires;
use App\Models\Company;
use App\Models\WorkersCompSetting;
use App\Support\Payroll\PayrollAccountNumber;
use App\Support\Payroll\PayrollContactPhone;
use App\Support\Payroll\PayStatementJurisdiction;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Payroll settings')] class extends Component {
    public Company $company;

    public int $f_standard_annual_hours = 2080;

    public string $f_overtime_threshold = '';

    public bool $f_banked_liability = false;

    public bool $f_portal_team_calendar = true;

    public string $f_business_number = '';

    public string $f_rp_account = '';

    /** T4 box 45 for every employee without their own code: '' (not chosen yet) or 1–5. */
    public string $f_dental_benefits_code = '';

    public string $f_rep_id = '';

    public string $f_rq_identification_number = '';

    public string $f_rq_file_number = '';

    public string $f_contact_name = '';

    public string $f_contact_email = '';

    public string $f_contact_phone = '';

    public string $f_work_location = '';

    public bool $f_federally_regulated = false;

    public string $f_remittance_frequency = 'monthly';

    /** @var array<int, array{province: string, rate: string, annual_max: string, board_account: string}> Per-province workers'-comp rates. */
    public array $f_wc = [];

    /** @var array<string, bool> Optional pay-statement display toggles. */
    public array $f_statement = [];

    /**
     * The discretionary pay-statement items the employer can show/hide (key =>
     * [label, default]). Legislatively required items are always shown regardless.
     *
     * @return array<string, array{label: string, default: bool}>
     */
    public function statementToggleDefs(): array
    {
        return [
            'ytd' => ['label' => __('Year-to-date (YTD) columns'), 'default' => true],
            'benefits_section' => ['label' => __('Employer-paid benefits & accruals section'), 'default' => true],
            'rate' => ['label' => __('Pay rate'), 'default' => true],
            'hours' => ['label' => __('Hours'), 'default' => true],
            'employer_address' => ['label' => __('Employer address'), 'default' => true],
            'occupation' => ['label' => __('Employee occupation / job title'), 'default' => true],
        ];
    }

    public function addWcRow(): void
    {
        $this->f_wc[] = ['province' => '', 'rate' => '', 'annual_max' => '', 'board_account' => ''];
    }

    public function removeWcRow(int $index): void
    {
        unset($this->f_wc[$index]);
        $this->f_wc = array_values($this->f_wc);
    }

    /** @return array<string, string> Non-Quebec province codes (Quebec uses CNESST). */
    #[Computed]
    public function wcProvinceOptions(): array
    {
        return collect(Country::Canada->regions())->reject(fn (string $label, string $code) => $code === 'QC')->all();
    }

    public function mount(Company $company): void
    {
        abort_unless($company->usesPayroll(), 404);

        $this->company = $company;
        $this->f_standard_annual_hours = (int) ($company->payroll_standard_annual_hours ?: 2080);
        $this->f_overtime_threshold = $company->payroll_overtime_weekly_threshold_hours !== null ? (string) (float) $company->payroll_overtime_weekly_threshold_hours : '';
        $this->f_banked_liability = (bool) $company->payroll_banked_overtime_liability;
        $this->f_portal_team_calendar = (bool) $company->portal_team_calendar;
        $this->f_business_number = $company->payroll_business_number ?? '';
        $this->f_rp_account = $company->payroll_rp_account ?? '';
        $this->f_dental_benefits_code = (string) ($company->payroll_dental_benefits_code?->value ?? '');
        $this->f_rep_id = $company->payroll_cra_rep_id ?? '';
        $this->f_rq_identification_number = $company->payroll_rq_identification_number ?? '';
        $this->f_rq_file_number = $company->payroll_rq_file_number ?? '';
        $this->f_contact_name = $company->payroll_contact_name ?? '';
        $this->f_contact_email = $company->payroll_contact_email ?? '';
        $this->f_contact_phone = $company->payroll_contact_phone ?? '';
        $this->f_work_location = $company->payroll_work_location ?? '';
        $this->f_federally_regulated = (bool) $company->payroll_federally_regulated;
        $this->f_remittance_frequency = $company->payroll_remittance_frequency?->value ?? 'monthly';

        $this->f_wc = $company->workersCompSettings()->orderBy('province')->get()->map(fn (WorkersCompSetting $s) => [
            'province' => $s->province,
            'rate' => (string) ((int) $s->rate_bp / 100),
            'annual_max' => $s->annual_max_assessable_cents !== null ? (string) ((int) $s->annual_max_assessable_cents / 100) : '',
            'board_account' => (string) ($s->board_account ?? ''),
        ])->all();

        foreach ($this->statementToggleDefs() as $key => $def) {
            $this->f_statement[$key] = $company->payStatementSetting($key, $def['default']);
        }
    }

    /**
     * The home jurisdiction profile (federal when federally regulated, else the
     * company's own province) — used to show which items are always required.
     *
     * @return array{name: string, legislation: string, retention: string, retention_months: int, requires_french: bool, required: array<int, string>}
     */
    #[Computed]
    public function homeJurisdiction(): array
    {
        return PayStatementJurisdiction::forProvince((string) $this->company->address_region, $this->f_federally_regulated);
    }

    /**
     * Labels of the items the home jurisdiction always requires (locked on).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function lockedItemLabels(): array
    {
        $labels = PayStatementJurisdiction::itemLabels();

        return array_values(array_map(fn (string $key): string => $labels[$key] ?? $key, $this->homeJurisdiction['required']));
    }

    /**
     * Write the CRA identifiers the way they are filed — no spaces, upper case,
     * "RP0001" for a bare "0001" — and split a whole payroll account (BN15)
     * typed into either field into its Business Number and RP account.
     */
    private function normalizeCraIdentifiers(): void
    {
        $businessNumber = PayrollAccountNumber::normalize($this->f_business_number);
        $rpAccount = PayrollAccountNumber::normalize($this->f_rp_account);

        foreach ([$businessNumber, $rpAccount] as $candidate) {
            if (preg_match(PayrollAccountNumber::PATTERN, $candidate) === 1
                && PayrollAccountNumber::compose($businessNumber, $rpAccount) === $candidate) {
                [$businessNumber, $rpAccount] = [substr($candidate, 0, 9), substr($candidate, 9)];
            }
        }

        $this->f_business_number = $businessNumber;
        $this->f_rp_account = PayrollAccountNumber::programAccount($rpAccount) ?? $rpAccount;
        $this->f_rep_id = strtoupper(trim($this->f_rep_id));
    }

    #[Requires(Activity::PayrollSetup, PermissionAction::Modify)]
    public function save(): void
    {
        $this->normalizeCraIdentifiers();

        // Revenu Québec numbers are stored bare: 1234567890 and RS0001. Spaces and
        // dashes go; a file number typed as its four digits gets the RS prefix.
        $this->f_rq_identification_number = preg_replace('/[\s-]+/', '', $this->f_rq_identification_number) ?? '';
        $this->f_rq_file_number = mb_strtoupper(preg_replace('/[\s-]+/', '', $this->f_rq_file_number) ?? '');

        if (preg_match('/^\d{4}$/', $this->f_rq_file_number) === 1) {
            $this->f_rq_file_number = 'RS'.$this->f_rq_file_number;
        }

        $validated = $this->validate([
            'f_standard_annual_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'f_overtime_threshold' => ['nullable', 'numeric', 'min:0', 'max:168'],
            'f_business_number' => ['nullable', 'string', 'regex:/^\d{9}$/'],
            'f_rp_account' => ['nullable', 'string', 'regex:/^RP\d{4}$/'],
            'f_dental_benefits_code' => ['nullable', 'in:'.implode(',', array_column(DentalBenefitsCode::cases(), 'value'))],
            'f_rep_id' => ['nullable', 'string', 'regex:/^[A-Z0-9]{1,7}$/'],
            'f_rq_identification_number' => ['nullable', 'regex:/^\d{10}$/'],
            'f_rq_file_number' => ['nullable', 'regex:/^RS\d{4}$/'],
            'f_contact_name' => ['nullable', 'string', 'max:255'],
            'f_contact_email' => ['nullable', 'email', 'max:255'],
            'f_contact_phone' => ['nullable', 'string', 'max:50', function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && PayrollContactPhone::parse((string) $value) === null) {
                    $fail(__('Enter a 10-digit phone number, for example 613-555-0101 (add an extension with “ext.”).'));
                }
            }],
            'f_work_location' => ['nullable', 'string', 'max:500'],
            'f_remittance_frequency' => ['required', 'in:'.implode(',', array_column(RemittanceFrequency::cases(), 'value'))],
            'f_wc' => ['array'],
            'f_wc.*.province' => ['nullable', 'string', 'max:2'],
            'f_wc.*.rate' => ['nullable', 'numeric', 'min:0'],
            'f_wc.*.annual_max' => ['nullable', 'numeric', 'min:0'],
            'f_wc.*.board_account' => ['nullable', 'string', 'max:50'],
        ], [
            'f_business_number.regex' => __('Enter the 9-digit Business Number, for example 123456789.'),
            'f_rp_account.regex' => __('Enter the payroll program account as RP and 4 digits, for example RP0001.'),
            'f_rep_id.regex' => __('Enter the CRA representative ID: up to 7 letters and digits.'),
            'f_rq_identification_number.regex' => __('The Revenu Québec identification number is 10 digits.'),
            'f_rq_file_number.regex' => __('The Revenu Québec file number is RS followed by 4 digits, for example RS0001.'),
        ]);

        // Merge the pay-statement toggles into the existing settings JSON.
        $settings = $this->company->settings ?? [];
        $settings['pay_statement'] = [];
        foreach ($this->statementToggleDefs() as $key => $def) {
            $settings['pay_statement'][$key] = (bool) ($this->f_statement[$key] ?? $def['default']);
        }

        $this->company->update([
            'payroll_standard_annual_hours' => $validated['f_standard_annual_hours'],
            'payroll_overtime_weekly_threshold_hours' => ($validated['f_overtime_threshold'] ?? '') !== '' ? (float) $validated['f_overtime_threshold'] : null,
            'payroll_banked_overtime_liability' => $this->f_banked_liability,
            'portal_team_calendar' => $this->f_portal_team_calendar,
            'payroll_business_number' => $validated['f_business_number'] ?: null,
            'payroll_rp_account' => $validated['f_rp_account'] ?: null,
            'payroll_dental_benefits_code' => ($validated['f_dental_benefits_code'] ?? '') !== '' ? DentalBenefitsCode::from((int) $validated['f_dental_benefits_code']) : null,
            'payroll_cra_rep_id' => $validated['f_rep_id'] ?: null,
            'payroll_rq_identification_number' => $validated['f_rq_identification_number'] ?: null,
            'payroll_rq_file_number' => $validated['f_rq_file_number'] ?: null,
            'payroll_contact_name' => $validated['f_contact_name'] ?: null,
            'payroll_contact_email' => $validated['f_contact_email'] ?: null,
            'payroll_contact_phone' => $validated['f_contact_phone'] ?: null,
            'payroll_work_location' => $validated['f_work_location'] ?: null,
            'payroll_federally_regulated' => $this->f_federally_regulated,
            'payroll_remittance_frequency' => $validated['f_remittance_frequency'],
            'settings' => $settings,
        ]);

        // Liability mode posts to Banked Time Payable (2435) — make sure the
        // account exists on companies created before it was seeded, instead of
        // failing at the first pay-run post (idempotent backfill).
        if ($this->f_banked_liability) {
            app(\App\Actions\Payroll\EnsurePayrollAccounts::class)->handle($this->company);
        }

        app(SaveWorkersCompSettings::class)->handle($this->company, $this->f_wc);

        Flux::toast(variant: 'success', text: __('Payroll settings saved.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout
        :heading="__('Payroll')"
        :subheading="__('Employer payroll identity and defaults used across pay runs, remittances and slips.')"
        contentClass="max-w-2xl"
    >
        <form wire:submit="save" class="space-y-8">
            {{-- CRA payroll program --}}
            <div class="rounded-lg border border-border p-5">
                <flux:heading size="lg" class="mb-1">{{ __('CRA payroll program') }}</flux:heading>
                <flux:text class="mb-4 text-sm text-muted-foreground">{{ __('Your Business Number and payroll (RP) program account. The PD7A shows them, and your T4 and T4A slips are filed with the CRA under them.') }}</flux:text>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:input wire:model="f_business_number" :label="__('Business Number')" placeholder="123456789" />
                    <flux:input wire:model="f_rp_account" :label="__('Payroll (RP) account')" placeholder="RP0001" />
                    <flux:select wire:model="f_dental_benefits_code" :label="__('Employer-offered dental benefits (T4 box 45)')" :description="__('On December 31, who could use dental care insurance or coverage you offered? Applies to every employee whose payroll setup doesn’t set its own code. Required to file T4 slips.')" class="md:col-span-2" data-test="payroll-dental-benefits-code">
                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @foreach (\App\Enums\DentalBenefitsCode::options() as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="f_rep_id" :label="__('Representative ID (RepID)')" :description="__('Only when a representative uploads your T4 or T4A XML through Represent a Client. Leave blank when you upload it yourself.')" placeholder="A1B2C3D" />
                </div>
                <flux:separator class="my-4" variant="subtle" />
                <flux:select wire:model.live="f_remittance_frequency" :label="__('Remittance frequency')" class="max-w-md">
                    @foreach (\App\Enums\RemittanceFrequency::options() as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:text class="mt-1 text-sm text-muted-foreground">{{ \App\Enums\RemittanceFrequency::from($f_remittance_frequency)->dueDateHint() }}</flux:text>
            </div>

            {{-- Revenu Québec source deductions (RL-1) --}}
            <div class="rounded-lg border border-border p-5">
                <flux:heading size="lg" class="mb-1">{{ __('Revenu Québec source deductions') }}</flux:heading>
                <flux:text class="mb-4 text-sm text-muted-foreground">{{ __('Your identification number and file number for source deductions, as printed on your Remittance of Source Deductions and Employer Contributions. RL-1 slips for employees who work in Quebec are filed under them; your QST number is a different account.') }}</flux:text>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:input wire:model="f_rq_identification_number" :label="__('Identification number')" placeholder="1234567890" data-test="rq-identification-number" />
                    <flux:input wire:model="f_rq_file_number" :label="__('File number')" placeholder="RS0001" data-test="rq-file-number" />
                </div>
            </div>

            {{-- Payroll defaults --}}
            <div class="rounded-lg border border-border p-5">
                <flux:heading size="lg" class="mb-1">{{ __('Defaults') }}</flux:heading>
                <flux:text class="mb-4 text-sm text-muted-foreground">{{ __('Company-wide payroll defaults.') }}</flux:text>
                <flux:input type="number" step="1" min="1" wire:model="f_standard_annual_hours" :label="__('Standard annual hours')" :description="__('Used to derive a salaried employee’s hourly rate for overtime. Default 2080 (52 weeks × 40h).')" class="max-w-xs" />

                <flux:input type="number" step="0.5" min="0" wire:model="f_overtime_threshold" :label="__('Weekly overtime threshold (hours)')" :description="__('When pulling time entries into a pay run, weekly hours past this are paid at 1.5×. Blank = no auto-split. Typical: 44. A single-threshold approximation, not per-province rules.')" class="max-w-xs" />

                <flux:switch wire:model="f_banked_liability" :label="__('Post banked overtime as a dollar liability')" :description="__('On: banking overtime posts DR wages / CR Banked Time Payable (2435), and taking it relieves the liability. Off: banked time is tracked in hours only, hitting the GL when the day is paid.')" data-test="banked-liability-toggle" />

                <flux:switch wire:model="f_portal_team_calendar" :label="__('Show the team time-off calendar in the employee portal')" :description="__('Employees see who is away (names and dates of approved time off only — no reasons or balances).')" data-test="team-calendar-toggle" />
            </div>

            {{-- Payroll contact + work location --}}
            <div class="rounded-lg border border-border p-5">
                <flux:heading size="lg" class="mb-1">{{ __('Contact & work location') }}</flux:heading>
                <flux:text class="mb-4 text-sm text-muted-foreground">{{ __('The person the CRA can contact about payroll, and the primary work location.') }}</flux:text>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:input wire:model="f_contact_name" :label="__('Payroll contact name')" />
                    <flux:input type="email" wire:model="f_contact_email" :label="__('Contact email')" />
                    <flux:input wire:model="f_contact_phone" :label="__('Contact phone')" />
                    <flux:input wire:model="f_work_location" :label="__('Work location')" :description="__('Primary work address.')" class="md:col-span-2" />
                </div>
            </div>

            {{-- Workers' compensation (WSIB/WCB) --}}
            <div class="rounded-lg border border-border p-5">
                <flux:heading size="lg" class="mb-1">{{ __('Workers’ compensation (WSIB/WCB)') }}</flux:heading>
                <flux:text class="mb-4 text-sm text-muted-foreground">{{ __('Your assessment rate per province (rate per $100 of payroll). Quebec is covered by CNESST and is not listed here.') }}</flux:text>

                <div class="space-y-2">
                    @foreach ($f_wc as $i => $row)
                        <div class="flex flex-wrap items-end gap-2" wire:key="wc-{{ $i }}">
                            <flux:select wire:model="f_wc.{{ $i }}.province" :label="__('Province')" class="w-28">
                                <flux:select.option value="">—</flux:select.option>
                                @foreach ($this->wcProvinceOptions as $code => $label)
                                    <flux:select.option value="{{ $code }}">{{ $code }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input type="number" step="0.01" wire:model="f_wc.{{ $i }}.rate" :label="__('Rate / $100')" inputmode="decimal" class="w-28" />
                            <flux:input type="number" step="0.01" wire:model="f_wc.{{ $i }}.annual_max" :label="__('Annual max ($)')" inputmode="decimal" class="w-36" :description="__('Per worker. Blank = none.')" />
                            <flux:input wire:model="f_wc.{{ $i }}.board_account" :label="__('Board account #')" class="w-36" />
                            <flux:button variant="ghost" size="sm" icon="trash" wire:click="removeWcRow({{ $i }})" :aria-label="__('Remove')" />
                        </div>
                    @endforeach
                </div>

                <flux:button variant="filled" size="sm" icon="plus" wire:click="addWcRow" class="mt-3">{{ __('Add province rate') }}</flux:button>
            </div>

            {{-- Pay statement --}}
            <div class="rounded-lg border border-border p-5">
                <flux:heading size="lg" class="mb-1">{{ __('Pay statement') }}</flux:heading>
                <flux:text class="mb-4 text-sm text-muted-foreground">{{ __('What appears on the pay statement each employee receives. The statement follows the employment/labour standards of the province where the employee works.') }}</flux:text>

                <flux:switch wire:model.live="f_federally_regulated" :label="__('Federally regulated employer')" :description="__('Banks, telecom, interprovincial transport, etc. — follow the Canada Labour Code Part III instead of provincial standards.')" />

                <flux:separator class="my-4" variant="subtle" />

                <flux:heading size="sm" class="mb-2">{{ __('Optional items') }}</flux:heading>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ($this->statementToggleDefs() as $key => $def)
                        <flux:checkbox wire:model="f_statement.{{ $key }}" :label="$def['label']" />
                    @endforeach
                </div>

                <div class="mt-4 rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                    <div class="font-medium text-foreground">{{ __(':statement — required items always shown', ['statement' => $this->homeJurisdiction['name']]) }}</div>
                    <div class="mt-1">{{ implode(' · ', $this->lockedItemLabels) }}</div>
                    <div class="mt-2 text-xs">{{ __('Per :legislation. Required items appear even if unchecked above.', ['legislation' => $this->homeJurisdiction['legislation']]) }}</div>
                </div>
            </div>

            <flux:button variant="primary" type="submit" data-test="payroll-settings-save">{{ __('Save') }}</flux:button>
        </form>
    </x-pages::settings.layout>
</section>
