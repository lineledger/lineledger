<?php

use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportComparison;
use App\Concerns\HasReportDateRange;
use App\Concerns\HasReportDimensions;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Services\Reporting\ReportCalculator;
use App\Services\Reporting\SalesPurchaseReportBuilder;
use App\Support\Reporting\ComparisonPeriod;
use App\Support\Reporting\ComparisonRow;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profit Insights')] class extends Component {
    use HasCustomReportHeader;
    use HasReportComparison;
    use HasReportDateRange;
    use HasReportDimensions;

    /** How many movers to surface in each "top drivers" list. */
    private const TOP_N = 5;

    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportDateRange();
    }

    /**
     * Profit Insights is fundamentally a comparison view, so an "off" basis still
     * compares to the immediately preceding period — there is always a prior to
     * explain the change against.
     */
    private function effectiveBasis(): string
    {
        return ComparisonPeriod::isOn($this->comparisonBasis)
            ? $this->comparisonBasis
            : ComparisonPeriod::PriorPeriod;
    }

    public function changePct(int $current, int $prior): ?float
    {
        return $prior !== 0 ? ($current - $prior) / abs($prior) * 100 : null;
    }

    /**
     * The whole report: profit/revenue/expense headlines for the current and
     * prior period, plus the top movers (customers, vendors, expense accounts)
     * ranked by the size of their change between the two periods.
     *
     * @return array{
     *     income: array{current: int, prior: int},
     *     expense: array{current: int, prior: int},
     *     profit: array{current: int, prior: int},
     *     prior_start: string, prior_end: string,
     *     expense_movers: list<array{label: string, current: int, prior: int, change: int}>,
     *     customer_movers: \Illuminate\Support\Collection<int, ComparisonRow>,
     *     vendor_movers: \Illuminate\Support\Collection<int, ComparisonRow>,
     * }
     */
    #[Computed]
    public function insights(): array
    {
        $calc = app(ReportCalculator::class);
        $builder = app(SalesPurchaseReportBuilder::class);

        $start = CarbonImmutable::parse($this->startDate);
        $end = CarbonImmutable::parse($this->endDate);
        $classId = $this->effectiveClassId();
        $locationId = $this->effectiveLocationId();

        [$priorStart, $priorEnd] = ComparisonPeriod::forRange($start, $end, $this->effectiveBasis(), $this->preset);

        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereIn('type', [AccountType::Income->value, AccountType::Expense->value])
            ->orderBy('code')
            ->get();

        $incomeCur = $incomePri = $expenseCur = $expensePri = 0;
        $expenseMovers = [];

        foreach ($accounts as $account) {
            $cur = $calc->periodChange($account, $start, $end, $classId, $locationId);
            $pri = $calc->periodChange($account, $priorStart, $priorEnd, $classId, $locationId);

            if ($account->type === AccountType::Income) {
                $incomeCur += $cur;
                $incomePri += $pri;

                continue;
            }

            $expenseCur += $cur;
            $expensePri += $pri;

            if ($cur !== 0 || $pri !== 0) {
                $expenseMovers[] = ['label' => $account->name, 'current' => $cur, 'prior' => $pri, 'change' => $cur - $pri];
            }
        }

        usort($expenseMovers, fn (array $a, array $b): int => abs($b['change']) <=> abs($a['change']));

        $topMovers = fn (\Illuminate\Support\Collection $cur, \Illuminate\Support\Collection $pri) => $builder
            ->mergeComparison($cur, $pri)
            ->sortByDesc(fn (ComparisonRow $r): int => abs($r->changeCents()))
            ->take(self::TOP_N)
            ->values();

        return [
            'income' => ['current' => $incomeCur, 'prior' => $incomePri],
            'expense' => ['current' => $expenseCur, 'prior' => $expensePri],
            'profit' => ['current' => $incomeCur - $expenseCur, 'prior' => $incomePri - $expensePri],
            'prior_start' => $priorStart->toDateString(),
            'prior_end' => $priorEnd->toDateString(),
            'expense_movers' => array_slice($expenseMovers, 0, self::TOP_N),
            'customer_movers' => $topMovers(
                $builder->salesByDimension($this->company, $start, $end, 'contact', $classId, $locationId),
                $builder->salesByDimension($this->company, $priorStart, $priorEnd, 'contact', $classId, $locationId),
            ),
            'vendor_movers' => $topMovers(
                $builder->purchasesByDimension($this->company, $start, $end, 'contact', $classId, $locationId),
                $builder->purchasesByDimension($this->company, $priorStart, $priorEnd, 'contact', $classId, $locationId),
            ),
        ];
    }
}; ?>

@php
    $money = fn (int $c) => number_format($c / 100, 2);
    $pctLabel = function (?float $p): string {
        if ($p === null) {
            return '—';
        }

        return ($p >= 0 ? '+' : '').number_format($p, 1).'%';
    };
@endphp

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Profit Insights'))"
        :subtitle="$company->name.' · '.$startDate.' '.__('to').' '.$endDate.' · '.__('vs :start to :end', ['start' => $this->insights['prior_start'], 'end' => $this->insights['prior_end']])"
        mode="range"
        :comparison="true"
        :tracks-classes="$this->tracksClasses"
        :tracks-locations="$this->tracksLocations"
        :classification-options="$this->classificationOptions"
        :location-options="$this->locationOptions"
        :exports="[]"
        :title-editable="true"
    />

    @php
        $profit = $this->insights['profit'];
        $income = $this->insights['income'];
        $expense = $this->insights['expense'];
        $profitDelta = $profit['current'] - $profit['prior'];
    @endphp

    {{-- Headline KPIs --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="rounded-lg border border-border p-4" data-test="kpi-revenue">
            <div class="text-sm text-muted-foreground">{{ __('Revenue') }}</div>
            <div class="mt-1 font-mono text-2xl font-semibold">{{ $money($income['current']) }}</div>
            <div class="mt-1 text-sm text-muted-foreground">
                {{ __('prior') }} {{ $money($income['prior']) }} · {{ $pctLabel($this->changePct($income['current'], $income['prior'])) }}
            </div>
        </div>
        <div class="rounded-lg border border-border p-4" data-test="kpi-expenses">
            <div class="text-sm text-muted-foreground">{{ __('Expenses') }}</div>
            <div class="mt-1 font-mono text-2xl font-semibold">{{ $money($expense['current']) }}</div>
            <div class="mt-1 text-sm text-muted-foreground">
                {{ __('prior') }} {{ $money($expense['prior']) }} · {{ $pctLabel($this->changePct($expense['current'], $expense['prior'])) }}
            </div>
        </div>
        <div class="rounded-lg border border-border p-4" data-test="kpi-profit">
            <div class="text-sm text-muted-foreground">{{ __('Profit') }}</div>
            <div class="mt-1 font-mono text-2xl font-semibold {{ $profit['current'] >= 0 ? 'text-green-600 dark:text-green-500' : 'text-red-600 dark:text-red-500' }}" data-test="profit-current">{{ $money($profit['current']) }}</div>
            <div class="mt-1 text-sm {{ $profitDelta >= 0 ? 'text-green-600 dark:text-green-500' : 'text-red-600 dark:text-red-500' }}" data-test="profit-delta">
                {{ ($profitDelta >= 0 ? '+' : '').$money($profitDelta) }} {{ __('vs prior') }} · {{ $pctLabel($this->changePct($profit['current'], $profit['prior'])) }}
            </div>
        </div>
    </div>

    {{-- Top movers --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Customers --}}
        <div class="rounded-lg border border-border">
            <div class="border-b border-border bg-muted px-4 py-2 font-medium">{{ __('Top customers by change') }}</div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-border">
                    @forelse ($this->insights['customer_movers'] as $row)
                        <tr data-test="customer-mover">
                            <td class="px-4 py-2">{{ $row->label }}</td>
                            <td class="px-4 py-2 text-right font-mono {{ $row->changeCents() >= 0 ? 'text-green-600 dark:text-green-500' : 'text-red-600 dark:text-red-500' }}">{{ ($row->changeCents() >= 0 ? '+' : '').$money($row->changeCents()) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-muted-foreground">{{ __('No customer activity to compare.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Vendors --}}
        <div class="rounded-lg border border-border">
            <div class="border-b border-border bg-muted px-4 py-2 font-medium">{{ __('Top vendors by change') }}</div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-border">
                    @forelse ($this->insights['vendor_movers'] as $row)
                        <tr data-test="vendor-mover">
                            <td class="px-4 py-2">{{ $row->label }}</td>
                            <td class="px-4 py-2 text-right font-mono {{ $row->changeCents() >= 0 ? 'text-red-600 dark:text-red-500' : 'text-green-600 dark:text-green-500' }}">{{ ($row->changeCents() >= 0 ? '+' : '').$money($row->changeCents()) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-muted-foreground">{{ __('No vendor activity to compare.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Expense categories --}}
        <div class="rounded-lg border border-border">
            <div class="border-b border-border bg-muted px-4 py-2 font-medium">{{ __('Top expense categories by change') }}</div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-border">
                    @forelse ($this->insights['expense_movers'] as $mover)
                        <tr data-test="expense-mover">
                            <td class="px-4 py-2">{{ $mover['label'] }}</td>
                            <td class="px-4 py-2 text-right font-mono {{ $mover['change'] >= 0 ? 'text-red-600 dark:text-red-500' : 'text-green-600 dark:text-green-500' }}">{{ ($mover['change'] >= 0 ? '+' : '').$money($mover['change']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-muted-foreground">{{ __('No expense activity to compare.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-4 text-xs text-muted-foreground">{{ __('Movers are ranked by the size of their change versus the prior period. For expenses and vendors, an increase (red) reduces profit; for revenue, an increase (green) lifts it.') }}</p>
</section>
