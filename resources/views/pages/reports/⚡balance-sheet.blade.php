<?php

use App\Concerns\EmailsReport;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportAsOfDate;
use App\Concerns\HasReportChart;
use App\Concerns\HasReportNotes;
use App\Concerns\HasReportNumberFormat;
use App\Concerns\Memorizable;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ReportStatement;
use App\Models\Account;
use App\Models\Company;
use App\Models\ReportSection;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\ReportCalculator;
use App\Services\Reporting\XlsxExporter;
use App\Support\Reporting\ComparisonPeriod;
use App\Support\Reporting\ReportChartBuilder;
use App\Support\Reporting\ReportDatePresets;
use App\Support\Reporting\SectionPartitioner;
use App\Support\Reporting\StatementLabels;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Balance Sheet')] class extends Component {
    use EmailsReport;
    use HasCustomReportHeader;
    use HasReportAsOfDate;
    use HasReportChart;
    use HasReportNotes;
    use HasReportNumberFormat;
    use Memorizable;

    public Company $company;

    #[Url(as: 'compare')]
    public string $comparisonBasis = 'off';

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportAsOfDate();
        $this->applyMemorized((int) request('memorized'));
    }

    protected function reportKey(): string
    {
        return 'reports.balance-sheet';
    }

    /** Org-type-aware statement vocabulary (Equity vs Net Assets, etc.). */
    #[Computed]
    public function labels(): StatementLabels
    {
        return StatementLabels::for($this->company);
    }

    #[Computed]
    public function showComparison(): bool
    {
        return ComparisonPeriod::isOn($this->comparisonBasis);
    }

    /**
     * The "as of" date for the comparison column, or null when comparison is
     * off. Prior period resolves to the period end immediately before the
     * current one (derived from the active preset); prior year subtracts a year.
     */
    public function comparisonAsOf(): ?CarbonImmutable
    {
        $range = ReportDatePresets::resolve(
            $this->asOfPreset,
            (int) ($this->company->fiscal_year_start_month ?? 1),
            $this->company->currentDateTime(),
        );

        return ComparisonPeriod::forAsOf(
            CarbonImmutable::parse($this->asOf),
            $this->comparisonBasis,
            $range !== null ? $range[0] : null,
        );
    }

    /** Subtitle suffix naming the resolved comparison date, or '' when off. */
    #[Computed]
    public function comparisonNote(): string
    {
        $priorAsOf = $this->comparisonAsOf();

        if ($priorAsOf === null) {
            return '';
        }

        return ' · '.__('compared to :date (:basis)', [
            'date' => $priorAsOf->toDateString(),
            'basis' => __(ComparisonPeriod::label($this->comparisonBasis)),
        ]);
    }

    /**
     * Each bucket is keyed by subtype value; each subtype carries a display label
     * and an ordered list of section/unassigned blocks (see SectionPartitioner).
     * Sections only regroup accounts — totals are unaffected.
     *
     * @return array{
     *   assets: array<string, array{label: string, blocks: array<int, array<string, mixed>>}>,
     *   liabilities: array<string, array{label: string, blocks: array<int, array<string, mixed>>}>,
     *   equity: array<string, array{label: string, blocks: array<int, array<string, mixed>>}>,
     *   total_assets: int, total_liabilities: int, total_equity: int,
     *   net_income_ytd: int,
     *   total_le: int,
     *   prior_total_assets: int, prior_total_liabilities: int, prior_total_equity: int,
     *   prior_net_income_ytd: int,
     *   prior_total_le: int,
     * }
     */
    #[Computed]
    public function report(): array
    {
        $asOf = CarbonImmutable::parse($this->asOf);
        $priorAsOf = $this->comparisonAsOf() ?? $asOf->subYear();
        $calc = app(ReportCalculator::class);

        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereIn('type', [AccountType::Asset->value, AccountType::Liability->value, AccountType::Equity->value])
            ->orderBy('code')
            ->get();

        $sections = ReportSection::query()
            ->where('company_id', $this->company->id)
            ->where('statement', ReportStatement::BalanceSheet->value)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('group_key');

        // Each bucket: subtypeValue => ['label' => string, 'rows' => [...]].
        $groups = ['assets' => [], 'liabilities' => [], 'equity' => []];
        $totals = ['assets' => 0, 'liabilities' => 0, 'equity' => 0];
        $priorTotals = ['assets' => 0, 'liabilities' => 0, 'equity' => 0];

        // Prior fiscal years' net income rolls into Retained Earnings (QuickBooks
        // behaviour). LineLedger posts no closing entries, so add it here.
        $priorEarnings = $calc->priorRetainedEarnings($this->company, $asOf);
        $priorEarningsCompare = $this->showComparison ? $calc->priorRetainedEarnings($this->company, $priorAsOf) : 0;
        $reAccountId = $accounts->first(fn ($a) => $a->subtype === AccountSubtype::RetainedEarnings)?->id;
        $priorEarningsApplied = false;

        foreach ($accounts as $account) {
            $balance = $calc->balanceAsOf($account, $asOf);
            $prior = $this->showComparison ? $calc->balanceAsOf($account, $priorAsOf) : 0;

            if ($account->id === $reAccountId) {
                if ($priorEarnings !== 0) {
                    $balance += $priorEarnings;
                    $priorEarningsApplied = true;
                }

                if ($priorEarningsCompare !== 0) {
                    $prior += $priorEarningsCompare;
                    $priorEarningsApplied = true;
                }
            }

            if ($balance === 0 && $prior === 0) {
                continue;
            }

            $bucket = match ($account->type) {
                AccountType::Asset => 'assets',
                AccountType::Liability => 'liabilities',
                AccountType::Equity => 'equity',
                default => null,
            };

            if (! $bucket) {
                continue;
            }

            $subtypeKey = $account->subtype->value;
            $groups[$bucket][$subtypeKey] ??= ['label' => $account->subtype->label(), 'rows' => []];
            $groups[$bucket][$subtypeKey]['rows'][] = [
                'id' => $account->id,
                'name' => $account->name,
                'code' => $account->code,
                'balance' => $balance,
                'prior' => $prior,
                'section_id' => $account->report_section_id,
            ];

            $totals[$bucket] += $balance;
            $priorTotals[$bucket] += $prior;
        }

        // No Retained Earnings account on the chart but prior earnings exist —
        // surface them as their own equity line so the statement still balances.
        if (! $priorEarningsApplied && ($priorEarnings !== 0 || $priorEarningsCompare !== 0)) {
            $reKey = AccountSubtype::RetainedEarnings->value;
            $groups['equity'][$reKey] ??= ['label' => $this->labels->retainedEarnings(), 'rows' => []];
            $groups['equity'][$reKey]['rows'][] = [
                'id' => null,
                'name' => $this->labels->retainedEarningsPriorRow(),
                'code' => '',
                'balance' => $priorEarnings,
                'prior' => $priorEarningsCompare,
                'section_id' => null,
            ];
            $totals['equity'] += $priorEarnings;
            $priorTotals['equity'] += $priorEarningsCompare;
        }

        // Partition each subtype's accounts into custom sections + Unassigned.
        $partitionBucket = function (array $bucketGroups) use ($sections): array {
            $out = [];

            foreach ($bucketGroups as $subtypeKey => $group) {
                $out[$subtypeKey] = [
                    'label' => $group['label'],
                    'blocks' => SectionPartitioner::partition(
                        $sections[$subtypeKey] ?? collect(),
                        $group['rows'],
                        'balance',
                    ),
                ];
            }

            return $out;
        };

        $netIncomeYtd = $calc->netIncomeYtd($this->company, $asOf);
        $priorNetIncomeYtd = $this->showComparison ? $calc->netIncomeYtd($this->company, $priorAsOf) : 0;

        return [
            'assets' => $partitionBucket($groups['assets']),
            'liabilities' => $partitionBucket($groups['liabilities']),
            'equity' => $partitionBucket($groups['equity']),
            'total_assets' => $totals['assets'],
            'total_liabilities' => $totals['liabilities'],
            'total_equity' => $totals['equity'],
            'net_income_ytd' => $netIncomeYtd,
            'total_le' => $totals['liabilities'] + $totals['equity'] + $netIncomeYtd,
            'prior_total_assets' => $priorTotals['assets'],
            'prior_total_liabilities' => $priorTotals['liabilities'],
            'prior_total_equity' => $priorTotals['equity'],
            'prior_net_income_ytd' => $priorNetIncomeYtd,
            'prior_total_le' => $priorTotals['liabilities'] + $priorTotals['equity'] + $priorNetIncomeYtd,
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
        return ReportChartBuilder::balanceSheet($this->report, $this->chartContext());
    }

    public function exportCsv()
    {
        $r = $this->report;
        $rows = collect();

        $headers = $this->showComparison
            ? ['Section', 'Subtype', 'Account', 'Current', 'Prior', 'Change', '% Change']
            : ['Section', 'Subtype', 'Account', 'Amount'];

        $chg = fn (int $c, int $p): string => CsvExporter::cents($c - $p);
        $pct = fn (int $c, int $p): string => $p !== 0 ? number_format(($c - $p) / abs($p) * 100, 1).'%' : '';

        $emit = function (string $section, int $sectionTotal, int $priorSectionTotal, ?string $displayName = null) use (&$rows, $r, $chg, $pct) {
            $name = $displayName ?? ucwords(str_replace('_', ' ', $section));
            $rows->push([strtoupper($name)]);

            foreach ($r[$section] as $group) {
                $rows->push(['', $group['label']]);
                foreach ($group['blocks'] as $block) {
                    if ($block['type'] === 'section') {
                        $rows->push(['', '', $block['name']]);
                    }
                    foreach ($block['rows'] as $a) {
                        $label = ($block['type'] === 'section' ? '    ' : '').$a['code'].' — '.$a['name'];
                        $row = ['', '', $label, CsvExporter::cents($a['balance'])];
                        if ($this->showComparison) {
                            $row[] = CsvExporter::cents($a['prior']);
                            $row[] = $chg($a['balance'], $a['prior']);
                            $row[] = $pct($a['balance'], $a['prior']);
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
            }

            $totalRow = ['', 'Total '.$name, '', CsvExporter::cents($sectionTotal)];
            if ($this->showComparison) {
                $totalRow[] = CsvExporter::cents($priorSectionTotal);
                $totalRow[] = $chg($sectionTotal, $priorSectionTotal);
                $totalRow[] = $pct($sectionTotal, $priorSectionTotal);
            }
            $rows->push($totalRow);
            $rows->push(['']);
        };

        $emit('assets', $r['total_assets'], $r['prior_total_assets']);
        $emit('liabilities', $r['total_liabilities'], $r['prior_total_liabilities']);
        $emit('equity', $r['total_equity'], $r['prior_total_equity'], $this->labels->equityShort());

        $niRow = ['', $this->labels->netIncomeYtd(), '', CsvExporter::cents($r['net_income_ytd'])];
        $leRow = ['', strtoupper($this->labels->totalLiabilitiesAndEquity()), '', CsvExporter::cents($r['total_le'])];
        if ($this->showComparison) {
            $niRow[] = CsvExporter::cents($r['prior_net_income_ytd']);
            $niRow[] = $chg($r['net_income_ytd'], $r['prior_net_income_ytd']);
            $niRow[] = $pct($r['net_income_ytd'], $r['prior_net_income_ytd']);
            $leRow[] = CsvExporter::cents($r['prior_total_le']);
            $leRow[] = $chg($r['total_le'], $r['prior_total_le']);
            $leRow[] = $pct($r['total_le'], $r['prior_total_le']);
        }
        $rows->push($niRow);
        $rows->push($leRow);

        return app(CsvExporter::class)->stream(
            "balance-sheet-{$this->asOf}.csv",
            $headers,
            $rows,
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->balanceSheet(
            "balance-sheet-{$this->asOf}.xlsx",
            $this->company,
            $this->report,
            $this->asOf,
            $this->showComparison,
            $this->numberFormat->xlsxMoneyFormat(),
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.balance-sheet', [
            'company' => $this->company,
            'report' => $this->report,
            'asOf' => $this->asOf,
            'showComparison' => $this->showComparison,
            'comparisonNote' => $this->comparisonNote,
            'title' => $this->effectiveTitle('Balance Sheet'),
            'labels' => $this->labels,
            'fmt' => $this->numberFormat,
            'notes' => $this->reportNotes,
        ], "balance-sheet-{$this->asOf}.pdf");
    }
}; ?>

@php $showComparison = $this->showComparison; @endphp
<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Balance Sheet'))"
        :subtitle="$company->name.' · '.__('as of').' '.$asOf.$this->comparisonNote.($this->numberFormat->unitsSuffix() ?? '')"
        mode="single"
        :comparison="true"
        :number-format="true"
        :sections-route="route('reports.balance-sheet.sections', ['company' => $company->slug])"
        :title-editable="true"
        :memorizable="true"
        :emailable="$this->canEmailReport()"
        :print-url="$this->printReportUrl()"
    />

    <x-reports.chart-panel
        class="mb-6"
        :charts="$this->chartData()"
        :title="$this->effectiveTitle(__('Balance Sheet'))"
        :period="__('as of').' '.$asOf.$this->comparisonNote"
    />

    @php
        $fmt = $this->numberFormat;
        // $ change and % change between current and prior. % is blank when there's
        // no prior figure to compare against (avoids a meaningless ÷0).
        $changeCell = fn (int $current, int $prior) => number_format(($current - $prior) / 100, 2);
        $pctCell = fn (int $current, int $prior) => $prior !== 0
            ? number_format(($current - $prior) / abs($prior) * 100, 1).'%'
            : '—';
        // A decrease (negative change) renders red, anything else stays neutral —
        // unlike the income statement, a balance sheet has no "good" direction.
        $changeClass = fn (int $current, int $prior) => ($current - $prior) < 0 ? 'text-red-600' : 'text-muted-foreground';
        // Shared column geometry so the header, account rows, and totals — which
        // live in separate <table>s — line up. Pairs with `table-fixed`.
        $cmpCols = '<colgroup><col><col style="width:8rem"><col style="width:8rem"><col style="width:8rem"><col style="width:5rem"></colgroup>';
    @endphp

    <div class="grid grid-cols-1 gap-6 {{ $showComparison ? '' : 'md:grid-cols-2' }}">
        @php
            $section = function ($title, $groups, $total, $priorTotal) {
                return compact('title', 'groups', 'total', 'priorTotal');
            };
        @endphp

        @foreach ([
            $section(__('Assets'), $this->report['assets'], $this->report['total_assets'], $this->report['prior_total_assets']),
            $section(__('Liabilities'), $this->report['liabilities'], $this->report['total_liabilities'], $this->report['prior_total_liabilities']),
        ] as $sec)
            <div class="rounded-lg border border-border">
                <div class="border-b border-border bg-muted px-4 py-2">
                    <flux:heading>{{ $sec['title'] }}</flux:heading>
                </div>
                <div class="p-4">
                    @if ($showComparison)
                        <table class="mb-2 w-full table-fixed text-xs uppercase tracking-wide text-muted-foreground">{!! $cmpCols !!}
                            <tr>
                                <th class="text-left font-normal"></th>
                                <th class="w-24 text-right font-normal">{{ __('Current') }}</th>
                                <th class="w-24 text-right font-normal">{{ __('Prior') }}</th>
                                <th class="w-24 text-right font-normal">{{ __('Change') }}</th>
                                <th class="w-16 text-right font-normal">{{ __('%') }}</th>
                            </tr>
                        </table>
                    @endif
                    @forelse ($sec['groups'] as $group)
                        @include('partials.reports.bs-subtype', ['group' => $group])
                    @empty
                        <flux:text class="text-muted-foreground">{{ __('No accounts.') }}</flux:text>
                    @endforelse
                </div>
                <div class="border-t border-border bg-muted px-4 py-2">
                    @if ($showComparison)
                        <table class="w-full table-fixed text-base font-semibold">{!! $cmpCols !!}
                            <tr>
                                <td>{{ __('Total') }} {{ $sec['title'] }}</td>
                                <td class="w-24 text-right font-mono {{ $fmt->cssClass($sec['total']) }}" data-test="bs-total-{{ strtolower($sec['title']) }}">{{ $fmt->format($sec['total']) }}</td>
                                <td class="w-24 text-right font-mono text-muted-foreground">{{ $fmt->format($sec['priorTotal']) }}</td>
                                <td class="w-24 text-right font-mono {{ $changeClass($sec['total'], $sec['priorTotal']) }}">{{ $changeCell($sec['total'], $sec['priorTotal']) }}</td>
                                <td class="w-16 text-right font-mono text-muted-foreground">{{ $pctCell($sec['total'], $sec['priorTotal']) }}</td>
                            </tr>
                        </table>
                    @else
                        <div class="flex justify-between text-base font-semibold">
                            <span>{{ __('Total') }} {{ $sec['title'] }}</span>
                            <span class="font-mono {{ $fmt->cssClass($sec['total']) }}" data-test="bs-total-{{ strtolower($sec['title']) }}">{{ $fmt->format($sec['total']) }}</span>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach

        <div class="rounded-lg border border-border {{ $showComparison ? '' : 'md:col-start-2' }}">
            <div class="border-b border-border bg-muted px-4 py-2">
                <flux:heading>{{ $this->labels->equityHeading() }}</flux:heading>
            </div>
            <div class="p-4">
                @if ($showComparison)
                    <table class="mb-2 w-full table-fixed text-xs uppercase tracking-wide text-muted-foreground">{!! $cmpCols !!}
                        <tr>
                            <th class="text-left font-normal"></th>
                            <th class="w-24 text-right font-normal">{{ __('Current') }}</th>
                            <th class="w-24 text-right font-normal">{{ __('Prior') }}</th>
                            <th class="w-24 text-right font-normal">{{ __('Change') }}</th>
                            <th class="w-16 text-right font-normal">{{ __('%') }}</th>
                        </tr>
                    </table>
                @endif
                @foreach ($this->report['equity'] as $group)
                    @include('partials.reports.bs-subtype', ['group' => $group])
                @endforeach

                <div class="mt-3 border-t border-border pt-2">
                    <table class="w-full text-sm {{ $showComparison ? 'table-fixed' : '' }}">@if ($showComparison){!! $cmpCols !!}@endif
                        <tr>
                            <td class="py-1 pl-3 italic">{{ $this->labels->netIncomeYtd() }}</td>
                            <td class="w-24 py-1 text-right font-mono {{ $fmt->cssClass($this->report['net_income_ytd']) }}" data-test="bs-net-income">{{ $fmt->format($this->report['net_income_ytd']) }}</td>
                            @if ($showComparison)
                                <td class="w-24 py-1 text-right font-mono text-muted-foreground">{{ $fmt->format($this->report['prior_net_income_ytd']) }}</td>
                                <td class="w-24 py-1 text-right font-mono {{ $changeClass($this->report['net_income_ytd'], $this->report['prior_net_income_ytd']) }}">{{ $changeCell($this->report['net_income_ytd'], $this->report['prior_net_income_ytd']) }}</td>
                                <td class="w-16 py-1 text-right font-mono text-muted-foreground">{{ $pctCell($this->report['net_income_ytd'], $this->report['prior_net_income_ytd']) }}</td>
                            @endif
                        </tr>
                    </table>
                </div>
            </div>
            <div class="border-t border-border bg-muted px-4 py-2">
                @if ($showComparison)
                    <table class="w-full table-fixed text-base font-semibold">{!! $cmpCols !!}
                        <tr>
                            <td>{{ $this->labels->totalLiabilitiesAndEquity() }}</td>
                            <td class="w-24 text-right font-mono {{ $fmt->cssClass($this->report['total_le']) }}" data-test="bs-total-le">{{ $fmt->format($this->report['total_le']) }}</td>
                            <td class="w-24 text-right font-mono text-muted-foreground">{{ $fmt->format($this->report['prior_total_le']) }}</td>
                            <td class="w-24 text-right font-mono {{ $changeClass($this->report['total_le'], $this->report['prior_total_le']) }}">{{ $changeCell($this->report['total_le'], $this->report['prior_total_le']) }}</td>
                            <td class="w-16 text-right font-mono text-muted-foreground">{{ $pctCell($this->report['total_le'], $this->report['prior_total_le']) }}</td>
                        </tr>
                    </table>
                @else
                    <div class="flex justify-between text-base font-semibold">
                        <span>{{ $this->labels->totalLiabilitiesAndEquity() }}</span>
                        <span class="font-mono {{ $fmt->cssClass($this->report['total_le']) }}" data-test="bs-total-le">{{ $fmt->format($this->report['total_le']) }}</span>
                    </div>
                @endif
                @if ($this->report['total_assets'] !== $this->report['total_le'])
                    <flux:text class="mt-2 text-red-600">{{ __('Balance sheet is out of balance — difference') }} {{ $fmt->format(abs($this->report['total_assets'] - $this->report['total_le'])) }}</flux:text>
                @endif
            </div>
        </div>
    </div>

    <x-reports.footer-notes :report-notes="$reportNotes" />
</section>
