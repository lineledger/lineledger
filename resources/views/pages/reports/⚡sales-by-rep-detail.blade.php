<?php

use App\Livewire\Attributes\Requires;
use App\Enums\PermissionAction;
use App\Concerns\HasColumnToggles;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportComparison;
use App\Concerns\HasReportDateRange;
use App\Concerns\HasReportDimensions;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\SalesPurchaseReportBuilder;
use App\Support\Reporting\ComparisonRow;
use App\Support\Reporting\RepSalesRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Sales by Rep (Detail)')] class extends Component {
    use HasColumnToggles;
    use HasCustomReportHeader;
    use HasReportComparison;
    use HasReportDateRange;
    use HasReportDimensions;

    /** 'accounts' = income-statement shape; 'documents' = the same, expanded per document. */
    #[Url(as: 'view')]
    public string $view = 'documents';

    public Company $company;

    public Contact $rep;

    public function mount(Company $company, Contact $rep): void
    {
        $this->company = $company;
        $this->rep = $rep;

        $this->initReportDateRange();
        $this->view = $this->sanitizeView($this->view);
    }

    public function updatedView(): void
    {
        $this->view = $this->sanitizeView($this->view);
    }

    private function sanitizeView(string $view): string
    {
        return in_array($view, ['accounts', 'documents'], true) ? $view : 'documents';
    }

    public function isAccountsView(): bool
    {
        return $this->view === 'accounts';
    }

    /**
     * Comparison is only meaningful against the account totals — an individual
     * invoice has no prior-period counterpart — so the columns (and the control
     * bar's Compare dropdown) are offered on the accounts view only.
     *
     * @return array<string, string>
     */
    public function columnRegistry(): array
    {
        if (! $this->isAccountsView() || ! $this->showComparison()) {
            return [];
        }

        return [
            'prior' => __('Prior'),
            'change' => __('Change'),
            'change_pct' => __('% Change'),
        ];
    }

    /**
     * This rep's sales for the period, one row per revenue account per document.
     * Totals tie to the rep's row on the Sales by Rep summary.
     *
     * @return Collection<int, RepSalesRow>
     */
    #[Computed]
    public function rows(): Collection
    {
        return app(SalesPurchaseReportBuilder::class)->salesByRepDetail(
            $this->company,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
            $this->rep->id,
            $this->effectiveClassId(),
            $this->effectiveLocationId(),
        );
    }

    /**
     * @return Collection<string, Collection<int, RepSalesRow>>
     */
    #[Computed]
    public function grouped(): Collection
    {
        return $this->rows->groupBy(fn (RepSalesRow $row): string => $row->accountLabel());
    }

    /**
     * The same rows for the comparison period, empty when comparison is off.
     *
     * @return Collection<int, RepSalesRow>
     */
    #[Computed]
    public function priorRows(): Collection
    {
        $range = $this->isAccountsView() && $this->showComparison() ? $this->comparisonRange() : null;

        if ($range === null) {
            return collect();
        }

        return app(SalesPurchaseReportBuilder::class)->salesByRepDetail(
            $this->company,
            $range[0],
            $range[1],
            $this->rep->id,
            $this->effectiveClassId(),
            $this->effectiveLocationId(),
        );
    }

    /**
     * One row per revenue account — the income-statement view. Ordered by
     * account code (the label is prefixed with it), not by size, so it reads
     * like the Income Statement rather than a ranking.
     *
     * @return Collection<int, ComparisonRow>
     */
    #[Computed]
    public function accountRows(): Collection
    {
        return app(SalesPurchaseReportBuilder::class)
            ->mergeComparison($this->aggregateByAccount($this->rows), $this->aggregateByAccount($this->priorRows))
            ->sortBy(fn (ComparisonRow $row): string => $row->label)
            ->values();
    }

    /**
     * Collapse per-document rows into the shape mergeComparison() consumes.
     *
     * @param  Collection<int, RepSalesRow>  $rows
     * @return Collection<int, array{key: int|null, label: string, qty: float, amount_cents: int}>
     */
    private function aggregateByAccount(Collection $rows): Collection
    {
        $byAccount = [];

        foreach ($rows as $row) {
            $key = (string) ($row->accountId ?? '');

            if (! isset($byAccount[$key])) {
                $byAccount[$key] = ['key' => $row->accountId, 'label' => $row->accountLabel(), 'qty' => 0.0, 'amount_cents' => 0];
            }

            $byAccount[$key]['amount_cents'] += $row->amountCents;
        }

        return collect(array_values($byAccount));
    }

    public function total(): int
    {
        return (int) $this->rows->sum(fn (RepSalesRow $r): int => $r->amountCents);
    }

    public function priorTotal(): int
    {
        return (int) $this->priorRows->sum(fn (RepSalesRow $r): int => $r->amountCents);
    }

    #[Requires(null, PermissionAction::Print)]
    public function exportCsv()
    {
        $filename = "sales-by-rep-{$this->rep->id}-{$this->startDate}-{$this->endDate}.csv";

        if ($this->isAccountsView()) {
            $pct = fn (?float $p): string => $p === null ? '' : number_format($p, 1).'%';

            if ($this->showComparison()) {
                $headers = ['Account', 'Sales', 'Prior', 'Change', '% Change'];
                $rows = $this->accountRows->map(fn (ComparisonRow $r): array => [
                    $r->label, CsvExporter::cents($r->amountCents), CsvExporter::cents($r->priorAmountCents),
                    CsvExporter::cents($r->changeCents()), $pct($r->changePct()),
                ]);
                $rows->push(['Total', CsvExporter::cents($this->total()), CsvExporter::cents($this->priorTotal()), CsvExporter::cents($this->total() - $this->priorTotal()), '']);
            } else {
                $headers = ['Account', 'Sales'];
                $rows = $this->accountRows->map(fn (ComparisonRow $r): array => [$r->label, CsvExporter::cents($r->amountCents)]);
                $rows->push(['Total', CsvExporter::cents($this->total())]);
            }

            return app(CsvExporter::class)->stream($filename, $headers, $rows);
        }

        $rows = $this->rows->map(fn (RepSalesRow $r): array => [
            $r->accountLabel(),
            $r->docDate,
            $r->docType,
            $r->docNo,
            $r->contact,
            CsvExporter::cents($r->amountCents),
        ]);
        $rows->push(['Total', '', '', '', '', CsvExporter::cents($this->total())]);

        return app(CsvExporter::class)->stream(
            $filename,
            ['Account', 'Date', 'Type', 'Document', 'Customer', 'Amount'],
            $rows,
        );
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Sales by Rep').' — '.$rep->display_name)"
        :subtitle="$company->name.' · '.$startDate.' '.__('to').' '.$endDate.($this->isAccountsView() ? $this->comparisonNote() : '')"
        mode="range"
        :comparison="$this->isAccountsView()"
        :tracks-classes="$this->tracksClasses"
        :tracks-locations="$this->tracksLocations"
        :classification-options="$this->classificationOptions"
        :location-options="$this->locationOptions"
        :exports="['csv']"
        :exports-disabled="$this->rows->isEmpty()"
    >
        <flux:select wire:model.live="view" :label="__('View')" class="max-w-[200px]" data-test="rep-detail-view">
            <flux:select.option value="documents">{{ __('Accounts & documents') }}</flux:select.option>
            <flux:select.option value="accounts">{{ __('Accounts only') }}</flux:select.option>
        </flux:select>

        @if ($this->columnRegistry())
            <x-reports.column-picker :columns="$this->columnRegistry()" />
        @endif

        <flux:button
            icon="arrow-left"
            variant="ghost"
            :href="route('reports.sales-by-rep', ['company' => $company->slug, 'start' => $startDate, 'end' => $endDate])"
            wire:navigate
            data-test="back-to-sales-by-rep"
        >{{ __('All reps') }}</flux:button>
    </x-reports.control-bar>

    @if ($this->isAccountsView())
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Revenue account') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Sales') }}</th>
                        @if ($this->showComparison() && $this->columnVisible('prior'))
                            <th class="px-4 py-2 text-right">{{ __('Prior') }}</th>
                        @endif
                        @if ($this->showComparison() && $this->columnVisible('change'))
                            <th class="px-4 py-2 text-right">{{ __('Change') }}</th>
                        @endif
                        @if ($this->showComparison() && $this->columnVisible('change_pct'))
                            <th class="px-4 py-2 text-right">{{ __('% Change') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->accountRows as $row)
                        <tr data-test="rep-account-row">
                            <td class="px-4 py-2">{{ $row->label }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($row->amountCents / 100, 2) }}</td>
                            @if ($this->showComparison() && $this->columnVisible('prior'))
                                <td class="px-4 py-2 text-right font-mono text-muted-foreground">{{ number_format($row->priorAmountCents / 100, 2) }}</td>
                            @endif
                            @if ($this->showComparison() && $this->columnVisible('change'))
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($row->changeCents() / 100, 2) }}</td>
                            @endif
                            @if ($this->showComparison() && $this->columnVisible('change_pct'))
                                <td class="px-4 py-2 text-right font-mono text-muted-foreground">{{ $row->changePct() === null ? '—' : number_format($row->changePct(), 1).'%' }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $this->visibleColumnCount(2) }}" class="px-4 py-6 text-center text-muted-foreground">{{ __('No sales in this period.') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($this->accountRows->isNotEmpty())
                    <tfoot class="bg-muted">
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ __('Total') }}</td>
                            <td class="px-4 py-3 text-right font-mono font-semibold" data-test="rep-accounts-total">{{ number_format($this->total() / 100, 2) }}</td>
                            @if ($this->showComparison() && $this->columnVisible('prior'))
                                <td class="px-4 py-3 text-right font-mono font-semibold text-muted-foreground">{{ number_format($this->priorTotal() / 100, 2) }}</td>
                            @endif
                            @if ($this->showComparison() && $this->columnVisible('change'))
                                <td class="px-4 py-3 text-right font-mono font-semibold">{{ number_format(($this->total() - $this->priorTotal()) / 100, 2) }}</td>
                            @endif
                            @if ($this->showComparison() && $this->columnVisible('change_pct'))
                                <td class="px-4 py-3 text-right font-mono font-semibold text-muted-foreground">{{ $this->priorTotal() !== 0 ? number_format(($this->total() - $this->priorTotal()) / abs($this->priorTotal()) * 100, 1).'%' : '—' }}</td>
                            @endif
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Doc #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->grouped as $account => $docs)
                    <tr class="bg-muted/50">
                        <td colspan="5" class="px-4 py-2 font-semibold" data-test="rep-detail-account">{{ $account }}</td>
                    </tr>
                    @foreach ($docs as $doc)
                        <tr data-test="rep-detail-row">
                            <td class="px-4 py-2 whitespace-nowrap">{{ $doc->docDate }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ $doc->docType }}</td>
                            <td class="px-4 py-2 font-mono">
                                <a
                                    href="{{ route($doc->routeName, ['company' => $company->slug, $doc->routeParam => $doc->docId]) }}"
                                    wire:navigate
                                    class="hover:underline"
                                >{{ $doc->docNo }}</a>
                            </td>
                            <td class="px-4 py-2">{{ $doc->contact }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($doc->amountCents / 100, 2) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td colspan="4" class="px-4 py-2 text-right font-medium">{{ __('Subtotal for :name', ['name' => $account]) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-medium" data-test="rep-detail-subtotal">{{ number_format($docs->sum(fn ($r) => $r->amountCents) / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-muted-foreground">{{ __('No sales in this period.') }}</td></tr>
                @endforelse
            </tbody>
            @if ($this->rows->isNotEmpty())
                <tfoot class="bg-muted">
                    <tr>
                        <td colspan="4" class="px-4 py-3 font-semibold">{{ __('Total') }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold" data-test="rep-detail-total">{{ number_format($this->total() / 100, 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
        </div>
    @endif
</section>
