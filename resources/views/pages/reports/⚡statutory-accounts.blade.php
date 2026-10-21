<?php

use App\Enums\JurisdictionCapability;
use App\Models\Account;
use App\Models\Company;
use App\Services\Reporting\UkStatutoryAccountsBuilder;
use App\Support\Statutory\StatutoryAccountsProfile;
use App\Support\Statutory\UkStatutoryCatalog;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Statutory Accounts')] class extends Component
{
    public Company $company;

    #[Url(as: 'start')]
    public string $startDate = '';

    #[Url(as: 'end')]
    public string $endDate = '';

    #[Url(as: 'tab')]
    public string $tab = 'accounts';

    /** Only show accounts with a balance or activity in the mapping tab. */
    public bool $activeOnly = true;

    /** @var array<string, mixed> the company details form */
    public array $form = [];

    /** Bumped on every save so the PDF preview reloads. */
    public int $version = 0;

    public function mount(Company $company): void
    {
        abort_unless($company->supports(JurisdictionCapability::UkStatutoryAccounts), 403, 'Statutory accounts are only available for UK companies.');

        $this->company = $company;
        $this->form = $this->formFor($this->profile());

        if (! $this->validDate($this->startDate) || ! $this->validDate($this->endDate)) {
            $this->useLastYear();
        }

        if (! in_array($this->tab, ['accounts', 'mapping', 'details'], true)) {
            $this->tab = 'accounts';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formFor(StatutoryAccountsProfile $profile): array
    {
        $form = $profile->toArray();
        $form['signatories'] = implode("\n", $form['signatories']);

        return $form;
    }

    private function profile(): StatutoryAccountsProfile
    {
        return StatutoryAccountsProfile::forCompany($this->company->fresh());
    }

    private function validDate(string $value): bool
    {
        return $value !== '' && strtotime($value) !== false;
    }

    /**
     * Snap the period to the last completed year ending on the company's
     * statutory year-end date.
     */
    public function useLastYear(int $offset = 0): void
    {
        [$start, $end] = $this->profile()->lastCompletedYear($this->company->currentDateTime()->toImmutable());
        $this->startDate = $start->addYears($offset)->toDateString();
        $this->endDate = $end->addYears($offset)->toDateString();
        $this->refresh();
    }

    public function shiftYear(int $years): void
    {
        if (! $this->validDate($this->startDate) || ! $this->validDate($this->endDate)) {
            return;
        }

        $this->startDate = CarbonImmutable::parse($this->startDate)->addYears($years)->toDateString();
        $this->endDate = CarbonImmutable::parse($this->endDate)->addYears($years)->toDateString();
        $this->refresh();
    }

    public function updatedStartDate(): void
    {
        $this->refresh();
    }

    public function updatedEndDate(): void
    {
        $this->refresh();
    }

    private function refresh(): void
    {
        unset($this->report, $this->mappingRows, $this->summaryYears, $this->rangeYears);
        $this->version++;
    }

    #[Computed]
    public function report(): ?array
    {
        if (! $this->validDate($this->startDate) || ! $this->validDate($this->endDate)) {
            return null;
        }

        $start = CarbonImmutable::parse($this->startDate);
        $end = CarbonImmutable::parse($this->endDate);

        if ($end->lt($start)) {
            return null;
        }

        // Accounts are for one year: a range spanning several shows the latest.
        [$start, $end] = $this->profile()->documentPeriod($start, $end);

        return app(UkStatutoryAccountsBuilder::class)->build($this->company, $start, $end);
    }

    /**
     * The statutory years inside the chosen range, when it spans more than one.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    #[Computed]
    public function rangeYears(): array
    {
        if (! $this->validDate($this->startDate) || ! $this->validDate($this->endDate)) {
            return [];
        }

        $years = $this->profile()->yearsWithin(CarbonImmutable::parse($this->startDate), CarbonImmutable::parse($this->endDate));

        return count($years) > 1 ? array_slice($years, -10) : [];
    }

    /**
     * One column per statutory year for the on-page summary: the selected year
     * through the latest completed one (or, when the selected year is the
     * latest, it and the year before), oldest first.
     *
     * @return list<array{label: string, end: string, sci: array<string, mixed>, sfp: array<string, mixed>}>
     */
    #[Computed]
    public function summaryYears(): array
    {
        $r = $this->report;

        if ($r === null) {
            return [];
        }

        $builder = app(UkStatutoryAccountsBuilder::class);
        $column = fn (array $built) => ['label' => $built['end']->format('Y'), 'end' => $built['end']->toDateString(), 'sci' => $built['sci']['current'], 'sfp' => $built['sfp']['current']];

        // A range spanning several years: a column for each year in it.
        if ($this->rangeYears !== []) {
            return array_map(
                fn (array $year) => $column($year[1]->equalTo($r['end']) ? $r : $builder->build($this->company, $year[0], $year[1])),
                $this->rangeYears,
            );
        }

        [, $latestEnd] = $this->profile()->lastCompletedYear($this->company->currentDateTime()->toImmutable());
        $count = max(1, $latestEnd->year - $r['end']->year + 1);
        $count = min($count, 10);

        $years = [];
        if ($count === 1 && $r['has_prior']) {
            $years[] = ['label' => $r['prior_end']->format('Y'), 'end' => $r['prior_end']->toDateString(), 'sci' => $r['sci']['prior'], 'sfp' => $r['sfp']['prior']];
        }

        for ($i = 0; $i < $count; $i++) {
            $built = $i === 0 ? $r : $builder->build($this->company, $r['start']->addYears($i), $r['end']->addYears($i));
            $years[] = ['label' => $built['end']->format('Y'), 'end' => $built['end']->toDateString(), 'sci' => $built['sci']['current'], 'sfp' => $built['sfp']['current']];
        }

        return $years;
    }

    public function pdfUrl(bool $download = false, ?string $format = null): string
    {
        return route('reports.statutory-accounts.pdf', array_filter([
            'company' => $this->company->slug,
            'start' => $this->startDate,
            'end' => $this->endDate,
            'download' => $download ? 1 : null,
            'format' => $format,
            'v' => $this->version,
        ]));
    }

    // ───────────────────────── Mapping ─────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function mappingRows(): array
    {
        $rows = app(UkStatutoryAccountsBuilder::class)->mapping($this->company);

        if (! $this->activeOnly) {
            return $rows;
        }

        $used = \Illuminate\Support\Facades\DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.company_id', $this->company->id)
            ->where('journal_lines.is_posted', true)
            ->distinct()
            ->pluck('journal_lines.account_id')
            ->flip();

        return array_values(array_filter($rows, fn ($r) => $used->has($r['id'])));
    }

    public function updatedActiveOnly(): void
    {
        unset($this->mappingRows);
    }

    public function setLine(int $accountId, string $line): void
    {
        $account = $this->ownedAccount($accountId);
        $account->forceFill(['statutory_line' => UkStatutoryCatalog::exists($line) ? $line : null])->save();
        $this->refresh();
        Flux::toast(variant: 'success', text: __('Line updated.'));
    }

    public function setLabel(int $accountId, string $label): void
    {
        $account = $this->ownedAccount($accountId);
        $label = trim(mb_substr($label, 0, 120));
        $account->forceFill(['statutory_label' => $label === '' ? null : $label])->save();
        $this->refresh();
        Flux::toast(variant: 'success', text: __('Caption updated.'));
    }

    private function ownedAccount(int $accountId): Account
    {
        $account = Account::withoutGlobalScopes()->findOrFail($accountId);
        abort_unless($account->company_id === $this->company->id, 403);

        return $account;
    }

    // ───────────────────────── Company details ─────────────────────────

    public function addDirector(): void
    {
        $this->form['directors'][] = ['name' => '', 'shares_current' => '', 'shares_prior' => ''];
    }

    public function removeDirector(int $index): void
    {
        unset($this->form['directors'][$index]);
        $this->form['directors'] = array_values($this->form['directors']);
    }

    public function addRate(): void
    {
        $this->form['depreciation_rates'][] = ['class' => '', 'rate' => '', 'method' => 'Straight line basis'];
    }

    public function removeRate(int $index): void
    {
        unset($this->form['depreciation_rates'][$index]);
        $this->form['depreciation_rates'] = array_values($this->form['depreciation_rates']);
    }

    public function saveDetails(): void
    {
        $this->validate([
            'form.framework' => ['required', 'in:'.implode(',', array_keys(StatutoryAccountsProfile::frameworks()))],
            'form.regime' => ['required', 'in:'.implode(',', array_keys(StatutoryAccountsProfile::regimes()))],
            'form.year_end_day' => ['required', 'integer', 'between:1,31'],
            'form.year_end_month' => ['required', 'integer', 'between:1,12'],
            'form.average_employees' => ['nullable', 'integer', 'min:0'],
            'form.approval_date' => ['nullable', 'date'],
        ], [], [
            'form.year_end_day' => __('year-end day'),
            'form.year_end_month' => __('year-end month'),
            'form.average_employees' => __('average number of employees'),
            'form.approval_date' => __('approval date'),
        ]);

        $form = $this->form;
        $form['signatories'] = preg_split('/\r?\n/', (string) ($form['signatories'] ?? ''));

        $profile = StatutoryAccountsProfile::fromArray($form);
        $profile->save($this->company);
        $this->form = $this->formFor($profile);
        $this->refresh();

        Flux::toast(variant: 'success', text: __('Statutory accounts details saved.'));
    }

    /**
     * The framework switch saves straight away — it changes the whole layout
     * of the accounts, so the preview should follow it.
     */
    public function updatedFormFramework(string $framework): void
    {
        $profile = StatutoryAccountsProfile::fromArray(array_merge($this->profile()->toArray(), ['framework' => $framework]));
        $profile->save($this->company);
        $this->form['framework'] = $profile->framework;
        $this->refresh();
    }
}; ?>

<section class="w-full">
    @php
        $r = $this->report;
        $frameworks = StatutoryAccountsProfile::frameworks();
        $fmt = fn (int $v) => \App\Support\Statutory\StatutoryFormat::amount($v);
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Statutory Accounts') }}</flux:heading>
            <flux:subheading>{{ $company->name }} · {{ __($frameworks[$form['framework']] ?? '') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <flux:input type="date" wire:model.live.debounce.500ms="startDate" :label="__('From')" data-test="statutory-start" />
            <flux:input type="date" wire:model.live.debounce.500ms="endDate" :label="__('To')" data-test="statutory-end" />
            <flux:button.group>
                <flux:button wire:click="shiftYear(-1)" icon="chevron-left" :aria-label="__('Previous year')" />
                <flux:button wire:click="useLastYear">{{ __('Last year end') }}</flux:button>
                <flux:button wire:click="shiftYear(1)" icon="chevron-right" :aria-label="__('Next year')" />
            </flux:button.group>
            <div style="width: 17rem;">
                <flux:select wire:model.live="form.framework" :label="__('Framework')" data-test="statutory-framework">
                    @foreach ($frameworks as $value => $label)
                        <flux:select.option :value="$value">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            {{-- The PDF opens in its own tab: the app's security headers forbid
                 framing any of its pages (X-Frame-Options: DENY), this one included. --}}
            <flux:tooltip :content="__('Open PDF in a new tab')">
                <flux:button icon="arrow-top-right-on-square" :href="$this->pdfUrl()" target="_blank" :aria-label="__('Open PDF in a new tab')" data-test="statutory-open-pdf" />
            </flux:tooltip>
            <flux:dropdown align="end">
                <flux:button variant="primary" icon="arrow-down-tray" icon:trailing="chevron-down" data-test="statutory-download">{{ __('Download') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="document" :href="$this->pdfUrl(true)" data-test="statutory-download-pdf">{{ __('PDF') }}</flux:menu.item>
                    <flux:menu.item icon="document-text" :href="$this->pdfUrl(true, 'docx')" data-test="statutory-download-docx">{{ __('Word (.docx)') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="mb-4 flex gap-1 border-b border-border">
        @foreach (['accounts' => __('Accounts'), 'mapping' => __('Account mapping'), 'details' => __('Company details')] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" data-test="statutory-tab-{{ $key }}"
                class="-mb-px border-b-2 px-4 py-2 text-sm {{ $tab === $key ? 'border-primary font-medium text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground' }}">{{ $label }}</button>
        @endforeach
    </div>

    {{-- ───────────── Accounts: summary + PDF preview ───────────── --}}
    @if ($tab === 'accounts')
        @if ($r === null)
            <flux:callout variant="warning" icon="exclamation-triangle">{{ __('Choose a period that ends after it starts.') }}</flux:callout>
        @else
            @foreach ($r['warnings'] as $warning)
                <flux:callout variant="warning" icon="exclamation-triangle" class="mb-2">{{ $warning }}</flux:callout>
            @endforeach

            @php $sci = $r['sci']['current']; $sfp = $r['sfp']['current']; @endphp
            <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                @foreach ([
                    __('Turnover') => $sci['turnover'],
                    __('Profit after tax') => $sci['profit_after_tax'],
                    __('Dividends') => $sci['dividends'],
                    __('Net assets') => $sfp['net_assets'],
                ] as $label => $value)
                    <div class="rounded-lg border border-border p-3">
                        <flux:text class="text-muted-foreground">{{ $label }}</flux:text>
                        <div class="text-lg font-mono" data-test="statutory-tile">£{{ $fmt($value) }}</div>
                    </div>
                @endforeach
            </div>

            @php
                $years = $this->summaryYears;
                $selectedEnd = $r['end']->toDateString();
                $sections = [
                    __('Income statement') => [
                        [__('Turnover'), fn ($y) => $y['sci']['turnover']],
                        [__('Expenses'), fn ($y) => -$y['sci']['expense_total']],
                        [__('Operating profit'), fn ($y) => $y['sci']['operating_profit']],
                        [__('Profit after tax'), fn ($y) => $y['sci']['profit_after_tax']],
                        [__('Dividends'), fn ($y) => -$y['sci']['dividends']],
                    ],
                    __('Balance sheet') => [
                        [__('Fixed assets'), fn ($y) => $y['sfp']['fixed_total']],
                        [__('Current assets'), fn ($y) => $y['sfp']['current_total']],
                        [__('Creditors within one year'), fn ($y) => -$y['sfp']['creditors_within']],
                        [__('Creditors after one year'), fn ($y) => -$y['sfp']['creditors_after']],
                        [__('Net assets'), fn ($y) => $y['sfp']['net_assets']],
                        [__('Share capital'), fn ($y) => $y['sfp']['share_capital']],
                        [__('Retained earnings'), fn ($y) => $y['sfp']['retained_earnings']],
                    ],
                ];
            @endphp
            <flux:text class="mb-2 block text-muted-foreground" data-test="statutory-summary-note">
                @if ($this->rangeYears !== [])
                    {{ __('Each year in the range, by year end. Accounts are for a single year, so the figures above and the PDF and Word documents are for the year ended :date (in bold), with its comparative.', ['date' => \App\Support\Statutory\StatutoryFormat::date($r['end'])]) }}
                @else
                    {{ __('The selected year (in bold) up to the latest completed year. The PDF and Word documents show the selected year with its comparative.') }}
                @endif
            </flux:text>
            <div class="space-y-4" data-test="statutory-summary">
                @foreach ($sections as $heading => $rows)
                    <div class="overflow-x-auto rounded-lg border border-border">
                        <table class="w-full text-sm" style="table-layout: fixed;">
                            <thead class="bg-muted">
                                <tr>
                                    <th class="px-3 py-2 text-left" style="width: 16rem;">{{ $heading }}</th>
                                    @foreach ($years as $y)
                                        <th class="px-3 py-2 text-right whitespace-nowrap {{ $y['end'] === $selectedEnd ? '' : 'font-normal text-muted-foreground' }}" data-test="statutory-summary-year">{{ $y['label'] }} £</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($rows as [$label, $value])
                                    <tr>
                                        <td class="px-3 py-1.5">{{ $label }}</td>
                                        @foreach ($years as $y)
                                            <td class="px-3 py-1.5 text-right font-mono {{ $y['end'] === $selectedEnd ? 'font-semibold' : 'text-muted-foreground' }}">{{ $fmt($value($y)) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- ───────────── Account mapping ───────────── --}}
    @if ($tab === 'mapping')
        <flux:text class="mb-3 block text-muted-foreground">
            {{ __('Each account presents on one line of the accounts. Accounts sharing a caption are added together — on the income statement, in the debtors and creditors notes, and as the asset classes of the fixed asset note. Leave a field blank to use the default shown.') }}
        </flux:text>
        <div class="mb-3"><flux:switch wire:model.live="activeOnly" :label="__('Only accounts with transactions')" /></div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm" data-test="statutory-mapping">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-3 py-2 text-left">{{ __('Account') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Line') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Caption') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($this->mappingRows as $row)
                        <tr wire:key="map-{{ $row['id'] }}">
                            <td class="px-3 py-2"><span class="font-mono text-muted-foreground">{{ $row['code'] }}</span> {{ $row['name'] }}</td>
                            <td class="px-3 py-2">
                                <select class="w-full rounded-md border border-border bg-transparent px-2 py-1 text-sm {{ $row['explicit_line'] ? 'font-medium' : 'text-muted-foreground' }}"
                                    wire:change="setLine({{ $row['id'] }}, $event.target.value)" data-test="statutory-line-{{ $row['id'] }}">
                                    <option value="">{{ __('Default') }} — {{ __(UkStatutoryCatalog::label($row['default_line'])) }}</option>
                                    @foreach (UkStatutoryCatalog::options() as $group => $options)
                                        <optgroup label="{{ __($group) }}">
                                            @foreach ($options as $opt)
                                                <option value="{{ $opt['value'] }}" @selected($row['explicit_line'] && $row['line'] === $opt['value'])>{{ __($opt['label']) }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                <input type="text" value="{{ $row['explicit_label'] ? $row['label'] : '' }}" placeholder="{{ $row['label'] }}"
                                    class="w-full rounded-md border border-border bg-transparent px-2 py-1 text-sm"
                                    wire:change="setLabel({{ $row['id'] }}, $event.target.value)" data-test="statutory-label-{{ $row['id'] }}" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ───────────── Company details ───────────── --}}
    @if ($tab === 'details')
        <form wire:submit="saveDetails" class="max-w-4xl space-y-6">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:select wire:model="form.regime" :label="__('Prepared under')">
                    @foreach (StatutoryAccountsProfile::regimes() as $value => $label)
                        <flux:select.option :value="$value">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="grid grid-cols-2 gap-2">
                    <flux:input type="number" min="1" max="31" wire:model="form.year_end_day" :label="__('Year-end day')" />
                    <flux:select wire:model="form.year_end_month" :label="__('Year-end month')">
                        @foreach (range(1, 12) as $m)
                            <flux:select.option :value="$m">{{ \Carbon\Carbon::create(2001, $m, 1)->translatedFormat('F') }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:input wire:model="form.company_number" :label="__('Company number')" />
                <flux:input wire:model="form.incorporated_in" :label="__('Incorporated in')" :placeholder="__('England and Wales')" />
                <flux:textarea wire:model="form.registered_office" :label="__('Registered office')" rows="4" />
                <flux:textarea wire:model="form.principal_activity" :label="__('Principal activity')" rows="4" />
            </div>

            <div>
                <flux:heading size="md" class="mb-2">{{ __('Directors') }}</flux:heading>
                <flux:text class="mb-2 block text-muted-foreground">{{ __('Shares held at the year end and the year before, for the directors’ report. Leave blank for none.') }}</flux:text>
                @if ($form['directors'] !== [])
                    <div class="mb-1 grid gap-2 text-sm font-medium" style="grid-template-columns: 1fr 9rem 9rem 2.5rem;">
                        <span>{{ __('Name') }}</span><span>{{ __('Shares (this year)') }}</span><span>{{ __('Shares (last year)') }}</span><span></span>
                    </div>
                @endif
                @foreach ($form['directors'] as $i => $d)
                    <div class="mb-2 grid items-center gap-2" style="grid-template-columns: 1fr 9rem 9rem 2.5rem;" wire:key="dir-{{ $i }}">
                        <flux:input wire:model="form.directors.{{ $i }}.name" :aria-label="__('Name')" />
                        <flux:input wire:model="form.directors.{{ $i }}.shares_current" :aria-label="__('Shares (this year)')" />
                        <flux:input wire:model="form.directors.{{ $i }}.shares_prior" :aria-label="__('Shares (last year)')" />
                        <flux:button wire:click="removeDirector({{ $i }})" icon="trash" variant="ghost" :aria-label="__('Remove')" />
                    </div>
                @endforeach
                <flux:button wire:click="addDirector" icon="plus" size="sm">{{ __('Add director') }}</flux:button>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="form.secretary" :label="__('Secretary')" />
                <div class="grid grid-cols-2 gap-2">
                    <flux:input wire:model="form.directors_report_signatory" :label="__('Directors’ report signed by')" />
                    <flux:input wire:model="form.directors_report_signatory_role" :label="__('as')" :placeholder="__('Director')" />
                </div>
                <flux:textarea wire:model="form.signatories" :label="__('Balance sheet signed by (one per line)')" rows="2" :placeholder="__('Defaults to the first two directors')" />
                <flux:input type="date" wire:model="form.approval_date" :label="__('Approved by the board on')" :description="__('Leave blank to print a dotted line.')" />
            </div>

            <div>
                <flux:heading size="md" class="mb-2">{{ __('Depreciation rates') }}</flux:heading>
                @if ($form['depreciation_rates'] !== [])
                    <div class="mb-1 grid gap-2 text-sm font-medium" style="grid-template-columns: 1fr 7rem 14rem 2.5rem;">
                        <span>{{ __('Asset class') }}</span><span>{{ __('Rate') }}</span><span>{{ __('Method') }}</span><span></span>
                    </div>
                @endif
                @foreach ($form['depreciation_rates'] as $i => $rate)
                    <div class="mb-2 grid items-center gap-2" style="grid-template-columns: 1fr 7rem 14rem 2.5rem;" wire:key="rate-{{ $i }}">
                        <flux:input wire:model="form.depreciation_rates.{{ $i }}.class" :aria-label="__('Asset class')" />
                        <flux:input wire:model="form.depreciation_rates.{{ $i }}.rate" :aria-label="__('Rate')" />
                        <flux:input wire:model="form.depreciation_rates.{{ $i }}.method" :aria-label="__('Method')" />
                        <flux:button wire:click="removeRate({{ $i }})" icon="trash" variant="ghost" :aria-label="__('Remove')" />
                    </div>
                @endforeach
                <flux:button wire:click="addRate" icon="plus" size="sm">{{ __('Add rate') }}</flux:button>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="form.authorised_shares" :label="__('Authorised share capital')" :placeholder="__('2,000 ordinary shares of £1 each')" />
                <flux:input wire:model="form.issued_shares" :label="__('Issued share capital')" :placeholder="__('1 ordinary share of £1')" />
                <flux:input type="number" min="0" wire:model="form.average_employees" :label="__('Average number of employees')" />
                <div></div>
                <flux:textarea wire:model="form.judgements_note" :label="__('Critical accounting estimates and judgements')" rows="3" />
                <flux:textarea wire:model="form.taxation_note" :label="__('Taxation note')" rows="3" />
                <flux:textarea wire:model="form.creditors_after_narrative" :label="__('Long-term creditors note narrative')" rows="3" />
                <flux:textarea wire:model="form.related_parties" :label="__('Related party transactions')" rows="3" />
                <flux:textarea wire:model="form.directors_advances" :label="__('Advances, credits and guarantees to directors')" rows="3" />
                <flux:textarea wire:model="form.guarantees" :label="__('Guarantees and other financial commitments')" rows="3" />
            </div>

            @if (($form['framework'] ?? '') === StatutoryAccountsProfile::FRAMEWORK_FRS105)
                <div class="space-y-2">
                    <flux:checkbox wire:model="form.include_directors_report" :label="__('Include the directors’ report')" />
                    <flux:checkbox wire:model="form.include_profit_and_loss_for_micro" :label="__('Include the profit and loss account (micro-entities need not file it)')" />
                </div>
            @endif

            <flux:button type="submit" variant="primary" data-test="statutory-save">{{ __('Save details') }}</flux:button>
        </form>
    @endif
</section>
