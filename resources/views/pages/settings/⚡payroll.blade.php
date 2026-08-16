<?php

use App\Actions\Payroll\SaveWorkersCompSettings;
use App\Enums\Country;
use App\Enums\RemittanceFrequency;
use App\Models\Company;
use App\Models\WorkersCompSetting;
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

    public function save(): void
    {
        $validated = $this->validate([
            'f_standard_annual_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'f_overtime_threshold' => ['nullable', 'numeric', 'min:0', 'max:168'],
            'f_business_number' => ['nullable', 'string', 'max:20'],
            'f_rp_account' => ['nullable', 'string', 'max:20'],
            'f_contact_name' => ['nullable', 'string', 'max:255'],
            'f_contact_email' => ['nullable', 'email', 'max:255'],
            'f_contact_phone' => ['nullable', 'string', 'max:50'],
            'f_work_location' => ['nullable', 'string', 'max:500'],
            'f_remittance_frequency' => ['required', 'in:'.implode(',', array_column(RemittanceFrequency::cases(), 'value'))],
            'f_wc' => ['array'],
            'f_wc.*.province' => ['nullable', 'string', 'max:2'],
            'f_wc.*.rate' => ['nullable', 'numeric', 'min:0'],
            'f_wc.*.annual_max' => ['nullable', 'numeric', 'min:0'],
            'f_wc.*.board_account' => ['nullable', 'string', 'max:50'],
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
                <flux:text class="mb-4 text-sm text-muted-foreground">{{ __('Your Business Number and payroll (RP) program account, used on the PD7A and T4/T4 Summary.') }}</flux:text>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:input wire:model="f_business_number" :label="__('Business Number')" placeholder="123456789" />
                    <flux:input wire:model="f_rp_account" :label="__('Payroll (RP) account')" placeholder="RP0001" />
                </div>
                <flux:separator class="my-4" variant="subtle" />
                <flux:select wire:model.live="f_remittance_frequency" :label="__('Remittance frequency')" class="max-w-md">
                    @foreach (\App\Enums\RemittanceFrequency::options() as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:text class="mt-1 text-sm text-muted-foreground">{{ \App\Enums\RemittanceFrequency::from($f_remittance_frequency)->dueDateHint() }}</flux:text>
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
