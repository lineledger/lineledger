<?php

use App\Actions\Accounting\RecognizeDeferredContribution;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportDateRange;
use App\Concerns\HasReportDimensions;
use App\Concerns\Memorizable;
use App\Enums\AccountType;
use App\Exceptions\Posting\PeriodLockedException;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use App\Enums\ReportStatement;
use App\Models\Account;
use App\Models\Company;
use App\Models\ReportSection;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\ReportCalculator;
use App\Services\Reporting\XlsxExporter;
use App\Support\Reporting\ComparisonPeriod;
use App\Support\Reporting\IncomeStatementBucket;
use App\Support\Reporting\SectionPartitioner;
use App\Support\Reporting\StatementLabels;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Statement of Operations')] class extends Component
{
    use HasCustomReportHeader;
    use HasReportDateRange;
    use HasReportDimensions;
    use Memorizable;

    public Company $company;

    #[Url(as: 'compare')]
    public string $comparisonBasis = 'off';

    // Deferred-contribution recognition (deferral method only).
    public string $recAmount = '';

    public ?int $recLiabilityAccountId = null;

    public ?int $recRevenueAccountId = null;

    public string $recDate = '';

    public string $recMemo = '';

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportDateRange();
        $this->applyMemorized((int) request('memorized'));

        $this->recDate = $this->company->currentDateTime()->toDateString();
    }

    /**
     * @return EloquentCollection<int, Account>
     */
    #[Computed]
    public function deferralLiabilityOptions(): EloquentCollection
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('type', AccountType::Liability->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * @return EloquentCollection<int, Account>
     */
    #[Computed]
    public function deferralRevenueOptions(): EloquentCollection
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('type', AccountType::Income->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function openRecognizeModal(): void
    {
        $this->recLiabilityAccountId ??= $this->deferralLiabilityOptions->firstWhere('code', '2500')?->id
            ?? $this->deferralLiabilityOptions->first()?->id;
        $this->recRevenueAccountId ??= $this->deferralRevenueOptions->firstWhere('code', '4100')?->id
            ?? $this->deferralRevenueOptions->first()?->id;
        $this->recDate = $this->recDate ?: $this->company->currentDateTime()->toDateString();

        Flux::modal('recognize-deferred')->show();
    }

    public function recognizeDeferred(RecognizeDeferredContribution $action): void
    {
        $this->validate([
            'recAmount' => ['required', 'numeric', 'gt:0'],
            'recLiabilityAccountId' => ['required', 'integer'],
            'recRevenueAccountId' => ['required', 'integer'],
            'recDate' => ['required', 'date'],
            'recMemo' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $action->handle(
                company: $this->company,
                liabilityAccountId: (int) $this->recLiabilityAccountId,
                revenueAccountId: (int) $this->recRevenueAccountId,
                amountCents: (int) round(((float) $this->recAmount) * 100),
                date: $this->recDate,
                memo: $this->recMemo ?: null,
            );
        } catch (InvalidArgumentException|PeriodLockedException $e) {
            $this->addError('recAmount', $e->getMessage());

            return;
        }

        Flux::modal('recognize-deferred')->close();
        $this->reset('recAmount', 'recMemo');
        unset($this->report);

        Flux::toast(variant: 'success', text: __('Deferred contribution recognized.'));
    }

    protected function reportKey(): string
    {
        return 'reports.statement-of-operations';
    }

    #[Computed]
    public function showComparison(): bool
    {
        return ComparisonPeriod::isOn($this->comparisonBasis);
    }

    /**
     * Prior comparison range [start, end], or null when comparison is off.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function comparisonRange(): ?array
    {
        return ComparisonPeriod::forRange(
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
            $this->comparisonBasis,
            $this->preset,
        );
    }

    /** Subtitle suffix naming the resolved comparison range, or '' when off. */
    #[Computed]
    public function comparisonNote(): string
    {
        $prior = $this->comparisonRange();

        if ($prior === null) {
            return '';
        }

        return ' · '.__('compared to :start to :end (:basis)', [
            'start' => $prior[0]->toDateString(),
            'end' => $prior[1]->toDateString(),
            'basis' => __(ComparisonPeriod::label($this->comparisonBasis)),
        ]);
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

        foreach ($accounts as $account) {
            $current = $calc->periodChange($account, $start, $end, $classId, $locationId, $fundId);
            $prior = $priorRange !== null ? $calc->periodChange($account, $priorRange[0], $priorRange[1], $classId, $locationId, $fundId) : 0;

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
            $gp = ['Gross profit', '', '', CsvExporter::cents($r['gross_profit'])];
            if ($this->showComparison) {
                $gp[] = CsvExporter::cents($r['prior_gross_profit']);
                $gp[] = $chg($r['gross_profit'], $r['prior_gross_profit']);
                $gp[] = $pct($r['gross_profit'], $r['prior_gross_profit']);
            }
            $rows->push($gp);
            $rows->push(['']);
        }
        $emit('expense', $r['expense'], $r['total_expense'], $r['prior_total_expense']);

        $ni = ['EXCESS (DEFICIENCY) OF REVENUE OVER EXPENSES', '', '', CsvExporter::cents($r['net_income'])];
        if ($this->showComparison) {
            $ni[] = CsvExporter::cents($r['prior_net_income']);
            $ni[] = $chg($r['net_income'], $r['prior_net_income']);
            $ni[] = $pct($r['net_income'], $r['prior_net_income']);
        }
        $rows->push($ni);

        return app(CsvExporter::class)->stream(
            "statement-of-operations-{$this->startDate}-{$this->endDate}.csv",
            $headers,
            $rows,
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->incomeStatement(
            "statement-of-operations-{$this->startDate}-{$this->endDate}.xlsx",
            $this->company,
            $this->report,
            $this->startDate,
            $this->endDate,
            $this->showComparison,
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.income-statement', [
            'company' => $this->company,
            'report' => $this->report,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'showComparison' => $this->showComparison,
            'comparisonNote' => $this->comparisonNote,
            'title' => $this->effectiveTitle('Statement of Operations'),
            'labels' => StatementLabels::for($this->company),
        ], "statement-of-operations-{$this->startDate}-{$this->endDate}.pdf");
    }
}; ?>

@php $showComparison = $this->showComparison; @endphp
<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Statement of Operations'))"
        :subtitle="$company->name.' · '.$startDate.' '.__('to').' '.$endDate.$this->comparisonNote"
        mode="range"
        :comparison="true"
        :tracks-classes="$this->tracksClasses"
        :tracks-locations="$this->tracksLocations"
        :classification-options="$this->classificationOptions"
        :location-options="$this->locationOptions"
        :sections-route="route('reports.income-statement.sections', ['company' => $company->slug])"
        :title-editable="true"
        :memorizable="true"
    />

    @if ($company->usesDeferralMethod())
        <div class="mb-4 flex justify-end">
            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="openRecognizeModal" data-test="recognize-deferred-trigger">
                {{ __('Recognize deferred contribution') }}
            </flux:button>
        </div>
    @endif

    @php
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
                    'income' => __('Revenue'),
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
                                    <td class="px-4 py-1 text-right font-mono">{{ number_format($a['current'] / 100, 2) }}</td>
                                    @if ($showComparison)
                                        <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ number_format($a['prior'] / 100, 2) }}</td>
                                        <td class="px-4 py-1 text-right font-mono {{ $changeClass($a['current'], $a['prior'], $isExpenseLike) }}">{{ $changeCell($a['current'], $a['prior']) }}</td>
                                        <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ $pctCell($a['current'], $a['prior']) }}</td>
                                    @endif
                                </tr>
                            @endforeach
                            @if ($block['type'] === 'section')
                                <tr class="border-t border-border">
                                    <td class="px-4 py-1 pl-8 text-sm italic text-muted-foreground">{{ __('Total') }} {{ $block['name'] }}</td>
                                    <td class="px-4 py-1 text-right font-mono italic text-muted-foreground" data-test="is-section-subtotal-{{ $block['id'] }}">{{ number_format($block['subtotal'] / 100, 2) }}</td>
                                    @if ($showComparison)
                                        <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ number_format($block['prior_subtotal'] / 100, 2) }}</td>
                                        <td class="px-4 py-1 text-right font-mono {{ $changeClass($block['subtotal'], $block['prior_subtotal'], $isExpenseLike) }}">{{ $changeCell($block['subtotal'], $block['prior_subtotal']) }}</td>
                                        <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ $pctCell($block['subtotal'], $block['prior_subtotal']) }}</td>
                                    @endif
                                </tr>
                            @endif
                        @endforeach
                        <tr class="border-t border-border">
                            <td class="px-4 py-2 pl-4 font-medium">{{ __('Total') }} {{ $label }}</td>
                            <td class="px-4 py-2 text-right font-mono font-medium" data-test="is-total-{{ $key }}">{{ number_format($total / 100, 2) }}</td>
                            @if ($showComparison)
                                <td class="px-4 py-2 text-right font-mono font-medium text-muted-foreground">{{ number_format($priorTotal / 100, 2) }}</td>
                                <td class="px-4 py-2 text-right font-mono font-medium {{ $changeClass($total, $priorTotal, $isExpenseLike) }}">{{ $changeCell($total, $priorTotal) }}</td>
                                <td class="px-4 py-2 text-right font-mono font-medium text-muted-foreground">{{ $pctCell($total, $priorTotal) }}</td>
                            @endif
                        </tr>

                        @if ($key === 'cogs')
                            <tr class="bg-muted">
                                <td class="px-4 py-2 font-semibold">{{ __('Gross Profit') }}</td>
                                <td class="px-4 py-2 text-right font-mono font-semibold" data-test="is-gross-profit">{{ number_format($this->report['gross_profit'] / 100, 2) }}</td>
                                @if ($showComparison)
                                    <td class="px-4 py-2 text-right font-mono font-semibold text-muted-foreground">{{ number_format($this->report['prior_gross_profit'] / 100, 2) }}</td>
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
                    <td class="px-4 py-3 font-semibold">{{ __('Excess (deficiency) of revenue over expenses') }}</td>
                    <td class="px-4 py-3 text-right font-mono font-semibold @if ($this->report['net_income'] < 0) text-red-600 @endif" data-test="is-net-income">{{ number_format($this->report['net_income'] / 100, 2) }}</td>
                    @if ($showComparison)
                        <td class="px-4 py-3 text-right font-mono font-semibold text-muted-foreground">{{ number_format($this->report['prior_net_income'] / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold {{ $changeClass($this->report['net_income'], $this->report['prior_net_income'], false) }}">{{ $changeCell($this->report['net_income'], $this->report['prior_net_income']) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold text-muted-foreground">{{ $pctCell($this->report['net_income'], $this->report['prior_net_income']) }}</td>
                    @endif
                </tr>
            </tfoot>
        </table>
    </div>

    <flux:modal name="recognize-deferred" class="md:w-96">
        <form wire:submit="recognizeDeferred" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Recognize deferred contribution') }}</flux:heading>
                <flux:subheading>{{ __('Move a deferred restricted contribution into revenue as the related expense is incurred.') }}</flux:subheading>
            </div>

            <flux:select wire:model="recLiabilityAccountId" :label="__('Deferred liability')" required data-test="rec-liability">
                @foreach ($this->deferralLiabilityOptions as $a)
                    <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="recRevenueAccountId" :label="__('Recognize into revenue')" required data-test="rec-revenue">
                @foreach ($this->deferralRevenueOptions as $a)
                    <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="recAmount" type="number" step="0.01" min="0" :label="__('Amount')" required data-test="rec-amount" />
                <flux:input wire:model="recDate" type="date" :label="__('Date')" required data-test="rec-date" />
            </div>

            <flux:input wire:model="recMemo" :label="__('Memo')" :placeholder="__('Optional')" data-test="rec-memo" />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="rec-submit">{{ __('Recognize') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
