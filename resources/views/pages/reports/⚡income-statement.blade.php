<?php

use App\Concerns\EmailsReport;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportBasis;
use App\Concerns\HasReportChart;
use App\Concerns\HasReportComparison;
use App\Concerns\HasReportDateRange;
use App\Concerns\HasReportDimensions;
use App\Concerns\HasReportNotes;
use App\Concerns\HasReportNumberFormat;
use App\Concerns\Memorizable;
use App\Enums\AccountType;
use App\Enums\ReportStatement;
use App\Models\Account;
use App\Models\Company;
use App\Models\ReportSection;
use App\Services\Reporting\CashBasisCalculator;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\ReportCalculator;
use App\Services\Reporting\XlsxExporter;
use App\Support\Reporting\IncomeStatementBucket;
use App\Support\Reporting\ReportChartBuilder;
use App\Support\Reporting\SectionPartitioner;
use App\Support\Reporting\StatementLabels;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Income Statement')] class extends Component
{
    use EmailsReport;
    use HasCustomReportHeader;
    use HasReportBasis;
    use HasReportChart;
    use HasReportComparison;
    use HasReportDateRange;
    use HasReportDimensions;
    use HasReportNotes;
    use HasReportNumberFormat;
    use Memorizable;

    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportDateRange();
        $this->applyMemorized((int) request('memorized'));
        $this->updatedReportBasis();
    }

    protected function reportKey(): string
    {
        return 'reports.income-statement';
    }

    /** Org-type-aware statement vocabulary (Net Income vs surplus, etc.). */
    #[Computed]
    public function labels(): StatementLabels
    {
        return StatementLabels::for($this->company);
    }

    /**
     * Each bucket is an ordered list of blocks (custom sections + an Unassigned
     * remainder); see SectionPartitioner. Totals are unaffected by sectioning.
     *
     * @return array{
     *   income: array<int, array<string, mixed>>,
     *   cogs: array<int, array<string, mixed>>,
     *   expense: array<int, array<string, mixed>>,
     *   total_income: int, total_cogs: int, total_expense: int, gross_profit: int, net_income: int,
     *   prior_total_income: int, prior_total_cogs: int, prior_total_expense: int, prior_gross_profit: int, prior_net_income: int,
     * }
     */
    #[Computed]
    public function report(): array
    {
        $calc = app(ReportCalculator::class);

        $start = CarbonImmutable::parse($this->startDate);
        $end = CarbonImmutable::parse($this->endDate);

        $classId = $this->effectiveClassId();
        $locationId = $this->effectiveLocationId();
        $fundId = $this->effectiveFundId();

        $priorRange = $this->comparisonRange();

        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereIn('type', [AccountType::Income->value, AccountType::Expense->value])
            ->orderBy('code')
            ->get();

        $sections = ReportSection::query()
            ->where('company_id', $this->company->id)
            ->where('statement', ReportStatement::IncomeStatement->value)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('group_key');

        $buckets = ['income' => [], 'cogs' => [], 'expense' => []];
        $totals = ['income' => 0, 'cogs' => 0, 'expense' => 0];
        $priorTotals = ['income' => 0, 'cogs' => 0, 'expense' => 0];

        // Cash basis swaps the per-account accrual activity for the payment-
        // timed figures; the row shape (and everything downstream — sections,
        // comparison, chart, exports) is unchanged.
        $cashCurrent = null;
        $cashPrior = null;

        if ($this->isCashBasis()) {
            $cashCalc = app(CashBasisCalculator::class);
            $cashCurrent = $cashCalc->periodChangesByAccount($this->company, $start, $end, $classId, $locationId, $fundId);
            $cashPrior = $priorRange !== null
                ? $cashCalc->periodChangesByAccount($this->company, $priorRange[0], $priorRange[1], $classId, $locationId, $fundId)
                : [];
        }

        foreach ($accounts as $account) {
            $current = $cashCurrent !== null
                ? ($cashCurrent[$account->id] ?? 0)
                : $calc->periodChange($account, $start, $end, $classId, $locationId, $fundId);
            $prior = $priorRange === null ? 0 : ($cashPrior !== null
                ? ($cashPrior[$account->id] ?? 0)
                : $calc->periodChange($account, $priorRange[0], $priorRange[1], $classId, $locationId, $fundId));

            if ($current === 0 && $prior === 0) {
                continue;
            }

            $bucket = IncomeStatementBucket::for($account);

            if (! $bucket) {
                continue;
            }

            $buckets[$bucket][] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'current' => $current,
                'prior' => $prior,
                'section_id' => $account->report_section_id,
            ];

            $totals[$bucket] += $current;
            $priorTotals[$bucket] += $prior;
        }

        $partition = fn (string $key): array => SectionPartitioner::partition(
            $sections[$key] ?? collect(),
            $buckets[$key],
            'current',
        );

        $grossProfit = $totals['income'] - $totals['cogs'];
        $netIncome = $grossProfit - $totals['expense'];

        $priorGrossProfit = $priorTotals['income'] - $priorTotals['cogs'];
        $priorNetIncome = $priorGrossProfit - $priorTotals['expense'];

        return [
            'income' => $partition('income'),
            'cogs' => $partition('cogs'),
            'expense' => $partition('expense'),
            'total_income' => $totals['income'],
            'total_cogs' => $totals['cogs'],
            'total_expense' => $totals['expense'],
            'gross_profit' => $grossProfit,
            'net_income' => $netIncome,
            'prior_total_income' => $priorTotals['income'],
            'prior_total_cogs' => $priorTotals['cogs'],
            'prior_total_expense' => $priorTotals['expense'],
            'prior_gross_profit' => $priorGrossProfit,
            'prior_net_income' => $priorNetIncome,
        ];
    }

    /**
     * Chart series for this report (default chart first). See ReportChartBuilder.
     *
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function chartData(): array
    {
        return ReportChartBuilder::incomeStatement($this->report, $this->chartContext());
    }

    public function exportCsv()
    {
        $r = $this->report;
        $rows = collect();

        $headers = $this->showComparison
            ? ['Section', 'Code', 'Account', 'Current', 'Prior', 'Change', '% Change']
            : ['Section', 'Code', 'Account', 'Amount'];

        $chg = fn (int $c, int $p): string => CsvExporter::cents($c - $p);
        $pct = fn (int $c, int $p): string => $p !== 0 ? number_format(($c - $p) / abs($p) * 100, 1).'%' : '';

        $emit = function (string $section, array $blocks, int $current, int $prior) use (&$rows, $chg, $pct) {
            $rows->push([strtoupper($section)]);
            foreach ($blocks as $block) {
                if ($block['type'] === 'section') {
                    $rows->push(['', '', $block['name']]);
                }
                foreach ($block['rows'] as $a) {
                    $row = ['', $a['code'], $a['name'], CsvExporter::cents($a['current'])];
                    if ($this->showComparison) {
                        $row[] = CsvExporter::cents($a['prior']);
                        $row[] = $chg($a['current'], $a['prior']);
                        $row[] = $pct($a['current'], $a['prior']);
                    }
                    $rows->push($row);
                }
                if ($block['type'] === 'section') {
                    $sub = ['', '', 'Total '.$block['name'], CsvExporter::cents($block['subtotal'])];
                    if ($this->showComparison) {
                        $sub[] = CsvExporter::cents($block['prior_subtotal']);
                        $sub[] = $chg($block['subtotal'], $block['prior_subtotal']);
                        $sub[] = $pct($block['subtotal'], $block['prior_subtotal']);
                    }
                    $rows->push($sub);
                }
            }
            $total = ['Total '.ucfirst($section), '', '', CsvExporter::cents($current)];
            if ($this->showComparison) {
                $total[] = CsvExporter::cents($prior);
                $total[] = $chg($current, $prior);
                $total[] = $pct($current, $prior);
            }
            $rows->push($total);
            $rows->push(['']);
        };

        $emit('income', $r['income'], $r['total_income'], $r['prior_total_income']);
        if (! empty($r['cogs'])) {
            $emit('cost of goods sold', $r['cogs'], $r['total_cogs'], $r['prior_total_cogs']);
            $gp = [$this->labels->grossProfit(), '', '', CsvExporter::cents($r['gross_profit'])];
            if ($this->showComparison) {
                $gp[] = CsvExporter::cents($r['prior_gross_profit']);
                $gp[] = $chg($r['gross_profit'], $r['prior_gross_profit']);
                $gp[] = $pct($r['gross_profit'], $r['prior_gross_profit']);
            }
            $rows->push($gp);
            $rows->push(['']);
        }
        $emit('expense', $r['expense'], $r['total_expense'], $r['prior_total_expense']);

        $ni = [strtoupper($this->labels->netIncome()), '', '', CsvExporter::cents($r['net_income'])];
        if ($this->showComparison) {
            $ni[] = CsvExporter::cents($r['prior_net_income']);
            $ni[] = $chg($r['net_income'], $r['prior_net_income']);
            $ni[] = $pct($r['net_income'], $r['prior_net_income']);
        }
        $rows->push($ni);

        return app(CsvExporter::class)->stream(
            "income-statement-{$this->startDate}-{$this->endDate}.csv",
            $headers,
            $rows,
        );
    }

    /** Filename/heading suffix naming the basis on cash exports. */
    private function basisSuffix(): string
    {
        return $this->isCashBasis() ? '-cash' : '';
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->incomeStatement(
            "income-statement-{$this->startDate}-{$this->endDate}{$this->basisSuffix()}.xlsx",
            $this->company,
            $this->report,
            $this->startDate,
            $this->endDate,
            $this->showComparison,
            $this->numberFormat->xlsxMoneyFormat(),
        );
    }

    public function exportPdf()
    {
        $title = $this->effectiveTitle('Income Statement');

        if ($this->isCashBasis()) {
            $title .= ' — '.$this->basisLabel();
        }

        return app(PdfExporter::class)->download('pdf.reports.income-statement', [
            'company' => $this->company,
            'report' => $this->report,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'showComparison' => $this->showComparison,
            'comparisonNote' => $this->comparisonNote,
            'title' => $title,
            'labels' => $this->labels,
            'fmt' => $this->numberFormat,
            'notes' => $this->reportNotes,
        ], "income-statement-{$this->startDate}-{$this->endDate}{$this->basisSuffix()}.pdf");
    }
}; ?>

@php $showComparison = $this->showComparison; @endphp
<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Income Statement'))"
        :subtitle="$company->name.' · '.$startDate.' '.__('to').' '.$endDate.($this->isCashBasis() ? ' · '.$this->basisLabel() : '').$this->comparisonNote.($this->numberFormat->unitsSuffix() ?? '')"
        mode="range"
        :comparison="true"
        :basis="true"
        :number-format="true"
        :tracks-classes="$this->tracksClasses"
        :tracks-locations="$this->tracksLocations"
        :classification-options="$this->classificationOptions"
        :location-options="$this->locationOptions"
        :sections-route="route('reports.income-statement.sections', ['company' => $company->slug])"
        :title-editable="true"
        :memorizable="true"
        :emailable="$this->canEmailReport()"
        :print-url="$this->printReportUrl()"
    />

    <x-reports.chart-panel
        class="mb-6"
        :charts="$this->chartData()"
        :title="$this->effectiveTitle(__('Income Statement'))"
        :period="$startDate.' '.__('to').' '.$endDate.$this->comparisonNote"
    />

    @php
        $fmt = $this->numberFormat;
        // $ change and % change between current and prior. % is blank when there's
        // no prior figure to compare against (avoids a meaningless ÷0).
        $changeCell = fn (int $current, int $prior) => number_format(($current - $prior) / 100, 2);
        $pctCell = fn (int $current, int $prior) => $prior !== 0
            ? number_format(($current - $prior) / abs($prior) * 100, 1).'%'
            : '—';
        // Change color reflects whether the movement is favorable. For income (and
        // profit) an increase is good (green) and a decrease is bad (red); expenses
        // are the opposite — a decrease is good. No movement stays neutral.
        $changeClass = function (int $current, int $prior, bool $isExpenseLike): string {
            $change = $current - $prior;

            if ($change === 0) {
                return 'text-muted-foreground';
            }

            $favorable = $isExpenseLike ? $change < 0 : $change > 0;

            return $favorable ? 'text-green-600' : 'text-red-600';
        };
        $colspan = $showComparison ? 5 : 2;
    @endphp

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Current') }}</th>
                    @if ($showComparison)
                        <th class="px-4 py-2 text-right">{{ __('Prior') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Change') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('% Change') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @php $bucketLabels = [
                    'income' => __('Income'),
                    'cogs' => __('Cost of Goods Sold'),
                    'expense' => __('Expenses'),
                ]; @endphp

                @foreach ($bucketLabels as $key => $label)
                    @php
                        $blocks = $this->report[$key];
                        $total = $this->report["total_{$key}"];
                        $priorTotal = $this->report["prior_total_{$key}"];
                        $isExpenseLike = in_array($key, ['cogs', 'expense'], true);
                    @endphp

                    @if (! empty($blocks))
                        <tr class="bg-muted"><td colspan="{{ $colspan }}" class="px-4 py-2 font-semibold">{{ $label }}</td></tr>
                        @foreach ($blocks as $block)
                            @if ($block['type'] === 'section')
                                <tr data-test="is-section-header"><td colspan="{{ $colspan }}" class="px-4 py-1 pl-6 font-medium text-muted-foreground">{{ $block['name'] }}</td></tr>
                            @endif
                            @foreach ($block['rows'] as $a)
                                <tr data-test="is-row">
                                    <td class="px-4 py-1 {{ $block['type'] === 'section' ? 'pl-12' : 'pl-8' }}">
                                        <a href="{{ route('reports.transactions', ['company' => $company->slug, 'account' => $a['id'], 'start' => $startDate, 'end' => $endDate, 'class' => $this->effectiveClassId(), 'location' => $this->effectiveLocationId(), 'fund' => $this->effectiveFundId()]) }}" wire:navigate class="hover:underline" data-test="drill-account">{{ $a['code'] }} — {{ $a['name'] }}</a>
                                    </td>
                                    <td class="px-4 py-1 text-right font-mono {{ $fmt->cssClass($a['current']) }}">{{ $fmt->format($a['current']) }}</td>
                                    @if ($showComparison)
                                        <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ $fmt->format($a['prior']) }}</td>
                                        <td class="px-4 py-1 text-right font-mono {{ $changeClass($a['current'], $a['prior'], $isExpenseLike) }}">{{ $changeCell($a['current'], $a['prior']) }}</td>
                                        <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ $pctCell($a['current'], $a['prior']) }}</td>
                                    @endif
                                </tr>
                            @endforeach
                            @if ($block['type'] === 'section')
                                <tr class="border-t border-border">
                                    <td class="px-4 py-1 pl-8 text-sm italic text-muted-foreground">{{ __('Total') }} {{ $block['name'] }}</td>
                                    <td class="px-4 py-1 text-right font-mono italic text-muted-foreground" data-test="is-section-subtotal-{{ $block['id'] }}">{{ $fmt->format($block['subtotal']) }}</td>
                                    @if ($showComparison)
                                        <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ $fmt->format($block['prior_subtotal']) }}</td>
                                        <td class="px-4 py-1 text-right font-mono {{ $changeClass($block['subtotal'], $block['prior_subtotal'], $isExpenseLike) }}">{{ $changeCell($block['subtotal'], $block['prior_subtotal']) }}</td>
                                        <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ $pctCell($block['subtotal'], $block['prior_subtotal']) }}</td>
                                    @endif
                                </tr>
                            @endif
                        @endforeach
                        <tr class="border-t border-border">
                            <td class="px-4 py-2 pl-4 font-medium">{{ __('Total') }} {{ $label }}</td>
                            <td class="px-4 py-2 text-right font-mono font-medium {{ $fmt->cssClass($total) }}" data-test="is-total-{{ $key }}">{{ $fmt->format($total) }}</td>
                            @if ($showComparison)
                                <td class="px-4 py-2 text-right font-mono font-medium text-muted-foreground">{{ $fmt->format($priorTotal) }}</td>
                                <td class="px-4 py-2 text-right font-mono font-medium {{ $changeClass($total, $priorTotal, $isExpenseLike) }}">{{ $changeCell($total, $priorTotal) }}</td>
                                <td class="px-4 py-2 text-right font-mono font-medium text-muted-foreground">{{ $pctCell($total, $priorTotal) }}</td>
                            @endif
                        </tr>

                        @if ($key === 'cogs')
                            <tr class="bg-muted">
                                <td class="px-4 py-2 font-semibold">{{ $this->labels->grossProfit() }}</td>
                                <td class="px-4 py-2 text-right font-mono font-semibold {{ $fmt->cssClass($this->report['gross_profit']) }}" data-test="is-gross-profit">{{ $fmt->format($this->report['gross_profit']) }}</td>
                                @if ($showComparison)
                                    <td class="px-4 py-2 text-right font-mono font-semibold text-muted-foreground">{{ $fmt->format($this->report['prior_gross_profit']) }}</td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold {{ $changeClass($this->report['gross_profit'], $this->report['prior_gross_profit'], false) }}">{{ $changeCell($this->report['gross_profit'], $this->report['prior_gross_profit']) }}</td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold text-muted-foreground">{{ $pctCell($this->report['gross_profit'], $this->report['prior_gross_profit']) }}</td>
                                @endif
                            </tr>
                        @endif
                    @endif
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr class="text-base">
                    <td class="px-4 py-3 font-semibold">{{ $this->labels->netIncome() }}</td>
                    <td class="px-4 py-3 text-right font-mono font-semibold {{ $fmt->cssClass($this->report['net_income']) }}" data-test="is-net-income">{{ $fmt->format($this->report['net_income']) }}</td>
                    @if ($showComparison)
                        <td class="px-4 py-3 text-right font-mono font-semibold text-muted-foreground">{{ $fmt->format($this->report['prior_net_income']) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold {{ $changeClass($this->report['net_income'], $this->report['prior_net_income'], false) }}">{{ $changeCell($this->report['net_income'], $this->report['prior_net_income']) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold text-muted-foreground">{{ $pctCell($this->report['net_income'], $this->report['prior_net_income']) }}</td>
                    @endif
                </tr>
            </tfoot>
        </table>
    </div>

    <x-reports.footer-notes :report-notes="$reportNotes" />
</section>
