<?php

use App\Actions\Payroll\FinalizeSlipFiling;
use App\Actions\Payroll\UnlockSlipFiling;
use App\Enums\PermissionAction;
use App\Enums\SlipType;
use App\Livewire\Attributes\Requires;
use App\Models\Company;
use App\Models\PayrollSlipFiling;
use App\Services\Reporting\Cra\CraFilingReadiness;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\T4ASlipCalculator;
use App\Services\Reporting\T4AXmlGenerator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('T4A slips')] class extends Component {
    public Company $company;

    #[Url(as: 'year')]
    public int $year = 0;

    #[Url(as: 'all')]
    public bool $showAll = false;

    public function mount(Company $company): void
    {
        abort_unless($company->supports(\App\Enums\JurisdictionCapability::T4ASlips), 404);

        $this->company = $company;

        if ($this->year === 0) {
            $this->year = (int) $company->currentDateTime()->subYear()->year;
        }
    }

    /**
     * The finalized filing for this year, if any. Its existence is the lock:
     * when present, the table, totals and exports all read the snapshot (which
     * holds only the reportable >= $500 slips, so the below-threshold toggle
     * is hidden while finalized).
     */
    #[Computed]
    public function filing(): ?PayrollSlipFiling
    {
        return PayrollSlipFiling::query()
            ->where('company_id', $this->company->id)
            ->where('slip_type', SlipType::T4a->value)
            ->where('year', $this->year)
            ->first();
    }

    #[Computed]
    public function rows(): array
    {
        if ($this->filing !== null) {
            return $this->filing->lines->map(fn ($line) => $line->data)->sortBy('name')->values()->all();
        }

        $start = CarbonImmutable::create($this->year, 1, 1)->startOfDay();
        $end = CarbonImmutable::create($this->year, 12, 31)->endOfDay();

        $rows = app(T4ASlipCalculator::class)->rows($this->company, $start, $end);

        if (! $this->showAll) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['meets_threshold']));
        }

        return $rows;
    }

    #[Requires(null, PermissionAction::Modify)]
    public function finalize(): void
    {
        app(FinalizeSlipFiling::class)->handle($this->company, SlipType::T4a, $this->year);

        $this->resetSnapshot();
    }

    #[Requires(null, PermissionAction::Modify)]
    public function unlock(): void
    {
        $filing = $this->filing;

        if ($filing !== null) {
            app(UnlockSlipFiling::class)->handle($filing);
        }

        $this->resetSnapshot();
    }

    private function resetSnapshot(): void
    {
        unset($this->filing, $this->rows, $this->filingSlips, $this->xmlReadiness);
    }

    public function totals(): array
    {
        return ['total' => array_sum(array_column($this->rows, 'box048_cents')), 'count' => count($this->rows)];
    }

    public function money(int $cents): string
    {
        return Money::fromCents($cents)->format();
    }

    #[Requires(null, PermissionAction::Print)]
    public function exportCsv()
    {
        $rows = collect($this->rows)->map(fn ($r) => [
            $r['name'], $r['tax_number'] ?? '', CsvExporter::cents($r['box048_cents']),
        ]);

        $rows->push(['TOTAL', '', CsvExporter::cents($this->totals()['total'])]);

        return app(CsvExporter::class)->stream(
            "t4a-{$this->year}.csv",
            ['Recipient', 'Business # / SIN', 'Box 048 (fees for services)'],
            $rows,
        );
    }

    #[Requires(null, PermissionAction::Print)]
    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.t4a', [
            'company' => $this->company,
            'rows' => $this->rows,
            'totals' => $this->totals(),
            'year' => $this->year,
        ], "t4a-{$this->year}.pdf");
    }

    /**
     * The slips and summary the CRA e-file carries: only the reportable
     * (>$500) recipients, whatever the show-below-threshold toggle says. When
     * finalized, the snapshot already holds exactly those and the locked summary.
     *
     * @return array{slips: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    #[Computed]
    public function filingSlips(): array
    {
        if ($this->filing !== null) {
            return [
                'slips' => $this->filing->lines->map(fn ($line) => $line->data)->sortBy('name')->values()->all(),
                'summary' => $this->filing->summary,
            ];
        }

        return [
            'slips' => app(T4ASlipCalculator::class)->slipsForYear($this->company, $this->year),
            'summary' => app(T4ASlipCalculator::class)->summary($this->company, $this->year),
        ];
    }

    /**
     * What stands between the reportable slips and a CRA XML file the CRA
     * accepts: errors block the download, warnings are shown beside it.
     */
    #[Computed]
    public function xmlReadiness(): CraFilingReadiness
    {
        return app(T4AXmlGenerator::class)->readiness($this->company, $this->year, $this->filingSlips['slips']);
    }

    #[Requires(null, PermissionAction::Print)]
    public function exportXml()
    {
        if (! $this->xmlReadiness->isReady()) {
            Flux::toast(variant: 'danger', text: __('The CRA XML can’t be downloaded yet. Fix the problems listed on this page first.'));

            return null;
        }

        ['slips' => $slips, 'summary' => $summary] = $this->filingSlips;

        $xml = app(T4AXmlGenerator::class)->generate($this->company, $this->year, $slips, $summary);

        return response()->streamDownload(
            fn () => print($xml),
            't4a-'.$this->year.'.xml',
            ['Content-Type' => 'application/xml'],
        );
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('T4A slips') }}</flux:heading>
            <flux:subheading>{{ __('Fees for services paid to T4A-tracked contractors (Box 048) by calendar year.') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <flux:input type="number" wire:model.live="year" :label="__('Year')" class="max-w-[120px]" />
            @if ($this->filing)
                <flux:badge color="green" data-test="t4a-finalized-badge">{{ __('Finalized') }}</flux:badge>
                @callable($this, 'unlock')
                <flux:button
                    variant="ghost"
                    icon="lock-open"
                    wire:click="unlock"
                    wire:confirm="{{ __('Unlock the :year T4A slips? They will revert to draft and recompute from posted payments.', ['year' => $this->year]) }}"
                    data-test="t4a-unlock-button"
                >
                    {{ __('Unlock') }}
                </flux:button>
                @endcallable
            @else
                <flux:badge color="zinc" data-test="t4a-draft-badge">{{ __('Draft') }}</flux:badge>
                @callable($this, 'finalize')
                <flux:button
                    variant="ghost"
                    icon="lock-closed"
                    wire:click="finalize"
                    wire:confirm="{{ __('Finalize the :year T4A slips? This locks the reportable (over $500) amounts as issued. You can unlock later to amend.', ['year' => $this->year]) }}"
                    data-test="t4a-finalize-button"
                >
                    {{ __('Finalize :year', ['year' => $this->year]) }}
                </flux:button>
                @endcallable
                <flux:switch wire:model.live="showAll" :label="__('Show below $500')" />
            @endif
            <flux:dropdown align="end">
                <flux:button variant="primary" icon="arrow-down-tray" icon:trailing="chevron-down" :disabled="empty($this->rows)">{{ __('Download') }}</flux:button>
                <flux:menu>
                    @callable($this, 'exportCsv')
                    <flux:menu.item icon="document-text" wire:click="exportCsv">{{ __('CSV') }}</flux:menu.item>
                    @endcallable
                    @callable($this, 'exportPdf')
                    <flux:menu.item icon="document" wire:click="exportPdf">{{ __('PDF') }}</flux:menu.item>
                    @endcallable
                    @callable($this, 'exportXml')
                    <flux:menu.item icon="code-bracket" wire:click="exportXml">{{ __('CRA XML (e-file)') }}</flux:menu.item>
                    @endcallable
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @if ($this->filing)
        <div class="mb-4 rounded-lg border border-border bg-muted/50 px-4 py-3 text-sm text-muted-foreground" data-test="t4a-finalized-note">
            {{ __('Finalized on :date by :user. Amounts below are the locked snapshot as issued; unlock to amend.', [
                'date' => $this->filing->finalized_at->toDateString(),
                'user' => $this->filing->finalizedBy?->name ?? __('unknown'),
            ]) }}
        </div>
    @endif

    @if (! empty($this->filingSlips['slips']))
        @php($readiness = $this->xmlReadiness)
        @if ($readiness->errors !== [])
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" data-test="t4a-xml-errors">
                <flux:callout.heading>{{ __('Before you download the CRA XML') }}</flux:callout.heading>
                <flux:callout.text>
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($readiness->errors as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2"><flux:link :href="route('settings.payroll', ['company' => $company])" wire:navigate>{{ __('Open Payroll settings') }}</flux:link></p>
                </flux:callout.text>
            </flux:callout>
        @endif
        @if ($readiness->warnings !== [])
            <flux:callout variant="warning" icon="information-circle" class="mb-4" data-test="t4a-xml-warnings">
                <flux:callout.heading>{{ __('Check before you file the CRA XML') }}</flux:callout.heading>
                <flux:callout.text>
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($readiness->warnings as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                </flux:callout.text>
            </flux:callout>
        @endif
    @endif

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Recipient') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Business # / SIN') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Box 048') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $row)
                    <tr data-test="t4a-row">
                        <td class="px-4 py-2">
                            {{ $row['name'] }}
                            @unless ($row['meets_threshold'])
                                <flux:badge size="sm" color="zinc" class="ml-1">{{ __('below $500') }}</flux:badge>
                            @endunless
                        </td>
                        <td class="px-4 py-2 font-mono text-muted-foreground">{{ $row['tax_number'] ?: '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold">{{ $this->money($row['box048_cents']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-muted-foreground">{{ __('No T4A-tracked contractors with payments in :year.', ['year' => $this->year]) }}</td></tr>
                @endforelse
            </tbody>
            @if (! empty($this->rows))
                <tfoot class="bg-muted">
                    <tr class="text-base">
                        <td colspan="2" class="px-4 py-2 text-right font-semibold">{{ trans_choice(':count recipient|:count recipients', $this->totals()['count'], ['count' => $this->totals()['count']]) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold" data-test="t4a-total">{{ $this->money($this->totals()['total']) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div class="mt-4 text-sm text-muted-foreground">
        <p>{{ __('Includes posted bill payments, posted cheques, and posted pay-now expenses to a T4A-tracked contractor in the year. Mark a vendor for T4A on its profile. Box 048 covers fees for services; issue a T4A when the total exceeds $500.') }}</p>
    </div>
</section>
