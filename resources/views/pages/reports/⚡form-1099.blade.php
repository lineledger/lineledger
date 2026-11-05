<?php

use App\Enums\JurisdictionCapability;
use App\Enums\PermissionAction;
use App\Livewire\Attributes\Requires;
use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\Form1099Calculator;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use App\Support\Money;
use App\Support\Tax\Form1099Threshold;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('1099 Summary')] class extends Component {
    public Company $company;

    #[Url(as: 'year')]
    public int $year = 0;

    #[Url(as: 'all')]
    public bool $showAll = false;

    public function mount(Company $company): void
    {
        // 1099 is a US federal form — the report has no meaning for other jurisdictions.
        abort_unless($company->supports(JurisdictionCapability::Form1099), 403, '1099 reporting is only available for US companies.');

        $this->company = $company;

        if ($this->year === 0) {
            // Default to the prior calendar year — the one you actually file for.
            $this->year = (int) $this->company->currentDateTime()->subYear()->year;
        }
    }

    /**
     * @return array<int, array{contact_id: int, name: string, tax_number: ?string, total_cents: int, meets_threshold: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        $start = CarbonImmutable::create($this->year, 1, 1)->startOfDay();
        $end = CarbonImmutable::create($this->year, 12, 31)->endOfDay();

        $rows = app(Form1099Calculator::class)->rows($this->company, $start, $end);

        if (! $this->showAll) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['meets_threshold']));
        }

        return $rows;
    }

    /**
     * The year's IRS reporting threshold, formatted. The threshold is a US-dollar
     * amount set by statute, so it is shown in dollars whatever the locale.
     */
    #[Computed]
    public function thresholdLabel(): string
    {
        return Money::fromCents(Form1099Threshold::centsFor($this->year), 'USD')->format();
    }

    public function totals(): array
    {
        return ['total' => array_sum(array_column($this->rows, 'total_cents')), 'count' => count($this->rows)];
    }

    #[Requires(null, PermissionAction::Print)]
    public function exportCsv()
    {
        $rows = collect($this->rows)->map(fn ($r) => [
            $r['name'], $r['tax_number'] ?? '', CsvExporter::cents($r['total_cents']),
        ]);

        $rows->push(['TOTAL', '', CsvExporter::cents($this->totals()['total'])]);

        return app(CsvExporter::class)->stream(
            "1099-summary-{$this->year}.csv",
            ['Vendor', 'Tax ID', 'Total paid'],
            $rows,
        );
    }

    #[Requires(null, PermissionAction::Print)]
    public function exportXlsx()
    {
        return app(XlsxExporter::class)->form1099(
            "1099-summary-{$this->year}.xlsx",
            $this->company,
            $this->rows,
            $this->year,
        );
    }

    #[Requires(null, PermissionAction::Print)]
    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.form-1099', [
            'company' => $this->company,
            'rows' => $this->rows,
            'totals' => $this->totals(),
            'year' => $this->year,
            // The PDF is printed in English, so the amount is too.
            'threshold' => Money::fromCents(Form1099Threshold::centsFor($this->year), 'USD')->format('en'),
        ], "1099-summary-{$this->year}.pdf");
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('1099 Summary') }}</flux:heading>
            <flux:subheading>{{ __('Total paid to 1099-tracked vendors (NEC Box 1) by calendar year.') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <flux:input type="number" wire:model.live="year" :label="__('Year')" class="max-w-[120px]" />
            <flux:switch wire:model.live="showAll" :label="__('Show below :amount', ['amount' => $this->thresholdLabel])" />
            <flux:dropdown align="end">
                <flux:button variant="primary" icon="arrow-down-tray" icon:trailing="chevron-down" :disabled="empty($this->rows)">{{ __('Download') }}</flux:button>
                <flux:menu>
                    @callable($this, 'exportCsv')
                    <flux:menu.item icon="document-text" wire:click="exportCsv">{{ __('CSV') }}</flux:menu.item>
                    @endcallable
                    @callable($this, 'exportXlsx')
                    <flux:menu.item icon="table-cells" wire:click="exportXlsx">{{ __('Excel') }}</flux:menu.item>
                    @endcallable
                    @callable($this, 'exportPdf')
                    <flux:menu.item icon="document" wire:click="exportPdf">{{ __('PDF') }}</flux:menu.item>
                    @endcallable
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @unless (Form1099Threshold::isPublished($this->year))
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6" data-test="form-1099-threshold-pending">
            <flux:callout.text>{{ __('The IRS has not published the :year reporting threshold yet, so this report uses the :latest threshold of :amount.', ['year' => $this->year, 'latest' => Form1099Threshold::latestPublishedYear(), 'amount' => $this->thresholdLabel]) }}</flux:callout.text>
        </flux:callout>
    @endunless

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Vendor') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Tax ID') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total paid') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $row)
                    <tr data-test="form-1099-row">
                        <td class="px-4 py-2">
                            <a href="{{ route('reports.contact-statement', ['company' => $company->slug, 'contact' => $row['contact_id'], 'kind' => 'ap']) }}" class="underline">{{ $row['name'] }}</a>
                            @unless ($row['meets_threshold'])
                                <flux:badge size="sm" color="zinc" class="ml-1">{{ __('below :amount', ['amount' => $this->thresholdLabel]) }}</flux:badge>
                            @endunless
                        </td>
                        <td class="px-4 py-2 font-mono text-muted-foreground">{{ $row['tax_number'] ?: '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($row['total_cents'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-muted-foreground">{{ __('No 1099-tracked vendors with payments in :year.', ['year' => $this->year]) }}</td></tr>
                @endforelse
            </tbody>
            @if (! empty($this->rows))
                <tfoot class="bg-muted">
                    <tr class="text-base">
                        <td colspan="2" class="px-4 py-2 text-right font-semibold">{{ trans_choice(':count vendor|:count vendors', $this->totals()['count'], ['count' => $this->totals()['count']]) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold" data-test="form-1099-total">{{ number_format($this->totals()['total'] / 100, 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div class="mt-4 space-y-2 text-sm text-muted-foreground">
        <p>{{ __('Includes posted bill payments, posted cheques, and posted pay-now expenses (EFT, ACH, or lines recorded from a bank import) to a 1099-tracked vendor in the year. Mark a vendor for 1099 on its profile.') }}</p>
        <p>{{ __('Leaves out payments charged to a credit card account or made with a payment method marked Card or payment app, such as a debit card or PayPal: the card company or app reports those on Form 1099-K, not 1099-NEC.') }}</p>
        <p>{{ __('The reporting threshold follows the year the payments were made in: $600 before 2026, $2,000 for 2026, and an amount the IRS adjusts for inflation from 2027.') }}</p>
    </div>
</section>
