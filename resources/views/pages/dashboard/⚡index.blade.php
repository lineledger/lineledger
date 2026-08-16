<?php

use App\Concerns\HasReportChart;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillPaymentStatus;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Enums\ChequeStatus;
use App\Enums\DataMigrationStatus;
use App\Enums\DepositStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\DataMigrationRun;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Services\Reporting\FinancialMetrics;
use App\Services\Reporting\ReportCalculator;
use App\Support\Reporting\ChartContext;
use App\Support\Reporting\ReportChartBuilder;
use App\Support\Reporting\ReportDatePresets;
use App\Support\Reporting\StatementLabels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    use HasReportChart;

    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * An in-progress QuickBooks import the user can resume, unless they've
     * permanently dismissed the reminder. Drives the "continue setup" banner.
     */
    #[Computed]
    public function resumableImport(): ?DataMigrationRun
    {
        if (data_get($this->company->settings, 'setup.migration_banner_dismissed')) {
            return null;
        }

        return DataMigrationRun::query()
            ->where('company_id', $this->company->id)
            ->where('status', DataMigrationStatus::InProgress)
            ->first();
    }

    /**
     * Hide the continue-setup banner for good (per company). The import is still
     * resumable from the "Import from QuickBooks" page in company settings.
     */
    public function dismissSetupBanner(): void
    {
        $settings = $this->company->settings ?? [];
        $settings['setup'] = array_merge($settings['setup'] ?? [], ['migration_banner_dismissed' => true]);

        $this->company->forceFill(['settings' => $settings])->save();

        unset($this->resumableImport);
    }

    /**
     * Sum of every bank / undeposited-funds account balance as of today,
     * with the percentage change versus 30 days ago.
     *
     * @return array{cents: int, changePct: ?float}
     */
    #[Computed]
    public function cashOnHand(): array
    {
        $calc = app(ReportCalculator::class);
        $today = $this->company->currentDateTime();
        $prior = $today->subDays(30);

        $accounts = Account::query()
            ->whereIn('subtype', array_map(fn ($subtype) => $subtype->value, FinancialMetrics::CASH_SUBTYPES))
            ->get();

        $current = 0;
        $past = 0;

        foreach ($accounts as $account) {
            $current += $calc->balanceAsOf($account, $today);
            $past += $calc->balanceAsOf($account, $prior);
        }

        return ['cents' => $current, 'changePct' => $this->pctChange($past, $current)];
    }

    /**
     * Receivable balance as of today, taken from the AR control account so it
     * reconciles with the AR Aging report and the general ledger (future-dated
     * invoices are not yet receivable). Count is the open invoices behind it.
     *
     * @return array{cents: int, count: int}
     */
    #[Computed]
    public function accountsReceivable(): array
    {
        $calc = app(ReportCalculator::class);
        $today = $this->company->currentDateTime();

        $cents = (int) Account::query()
            ->where('subtype', AccountSubtype::AccountsReceivable->value)
            ->get()
            ->sum(fn (Account $a) => $calc->balanceAsOf($a, $today));

        $count = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value])
            ->where('invoice_date', '<=', $today)
            ->whereRaw('total_cents - amount_paid_cents > 0')
            ->count();

        return ['cents' => $cents, 'count' => $count];
    }

    /**
     * Payable balance as of today from the AP control account (matches AP Aging
     * and the GL), plus how many open bills fall due within 7 days.
     *
     * @return array{cents: int, dueThisWeek: int}
     */
    #[Computed]
    public function accountsPayable(): array
    {
        $calc = app(ReportCalculator::class);
        $today = $this->company->currentDateTime();

        $cents = (int) Account::query()
            ->where('subtype', AccountSubtype::AccountsPayable->value)
            ->get()
            ->sum(fn (Account $a) => $calc->balanceAsOf($a, $today));

        $dueThisWeek = Bill::query()
            ->whereIn('status', [BillStatus::Posted->value, BillStatus::Partial->value])
            ->where('bill_date', '<=', $today)
            ->whereRaw('total_cents - amount_paid_cents > 0')
            ->whereBetween('due_date', [$today->startOfDay(), $today->startOfDay()->addDays(7)])
            ->count();

        return ['cents' => $cents, 'dueThisWeek' => $dueThisWeek];
    }

    /**
     * Month-to-date net income (income − expense) with the change versus the
     * equivalent span of the prior month.
     *
     * @return array{cents: int, changePct: ?float}
     */
    #[Computed]
    public function netIncomeMtd(): array
    {
        $calc = app(ReportCalculator::class);

        $now = $this->company->currentDateTime();
        $start = $now->startOfMonth();

        $current = $calc->totalForType($this->company, AccountType::Income, $start, $now)
            - $calc->totalForType($this->company, AccountType::Expense, $start, $now);

        $daysElapsed = $start->diffInDays($now);
        $priorStart = $start->subMonthNoOverflow();
        $priorEnd = $priorStart->addDays($daysElapsed);

        $prior = $calc->totalForType($this->company, AccountType::Income, $priorStart, $priorEnd)
            - $calc->totalForType($this->company, AccountType::Expense, $priorStart, $priorEnd);

        return ['cents' => $current, 'changePct' => $this->pctChange($prior, $current)];
    }

    /**
     * Actual cash movement for the last six calendar months, oldest first.
     * Derived from posted journal lines hitting cash accounts so it stays
     * consistent with "Cash on hand": debits to cash are inflow, credits outflow.
     * This captures deposits, opening balances, receipts, payments and cheques.
     *
     * @return array<int, array{label: string, inflow: int, outflow: int}>
     */
    #[Computed]
    public function cashFlow(): array
    {
        $accountIds = $this->cashAccountIds();
        $now = $this->company->currentDateTime();
        $rows = [];

        for ($i = 5; $i >= 0; $i--) {
            $monthStart = $now->subMonths($i)->startOfMonth();
            $monthEnd = $monthStart->endOfMonth();

            // Net each entry's cash lines so transfers between cash accounts
            // (e.g. undeposited funds → bank on a deposit) cancel out instead of
            // inflating both bars. A positive net is an inflow, negative an outflow.
            $entries = JournalLine::query()
                ->whereIn('account_id', $accountIds)
                ->whereHas('journalEntry', fn ($q) => $q
                    ->where('is_posted', true)
                    ->whereBetween('entry_date', [$monthStart, $monthEnd])
                )
                ->selectRaw('journal_entry_id, SUM(debit_cents - credit_cents) AS net')
                ->groupBy('journal_entry_id')
                ->get();

            $inflow = 0;
            $outflow = 0;

            foreach ($entries as $entry) {
                $net = (int) $entry->net;

                if ($net > 0) {
                    $inflow += $net;
                } elseif ($net < 0) {
                    $outflow += -$net;
                }
            }

            $rows[] = [
                'label' => $monthStart->format('M'),
                'inflow' => $inflow,
                'outflow' => $outflow,
            ];
        }

        return $rows;
    }

    /** Chart context for the dashboard (single period, home currency). */
    private function dashboardChartContext(): ChartContext
    {
        return new ChartContext(
            currency: $this->company->currency_code ?? 'USD',
            labels: StatementLabels::for($this->company),
        );
    }

    /**
     * The six-month cash-flow series as a Chart.js grouped bar.
     *
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function cashFlowChart(): array
    {
        return ReportChartBuilder::dashboardCashFlow($this->cashFlow, $this->dashboardChartContext());
    }

    /**
     * Fiscal year-to-date income / expenses / net income snapshot. Reuses the
     * same grouped totals the "Net income (MTD)" KPI card already relies on.
     *
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function plSnapshotChart(): array
    {
        $calc = app(ReportCalculator::class);
        $now = $this->company->currentDateTime();
        $range = ReportDatePresets::resolve(
            'this_fiscal_year_to_date',
            (int) ($this->company->fiscal_year_start_month ?? 1),
            $now,
        );
        [$start, $end] = $range ?? [$now->startOfYear(), $now];

        $income = $calc->totalForType($this->company, AccountType::Income, $start, $end);
        $expense = $calc->totalForType($this->company, AccountType::Expense, $start, $end);

        return ReportChartBuilder::plSnapshot($income, $expense, $income - $expense, $this->dashboardChartContext());
    }

    /**
     * Cash / receivable / payable position as of today, built from figures the
     * KPI cards already compute (no extra queries).
     *
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function positionChart(): array
    {
        return ReportChartBuilder::positionSnapshot(
            $this->cashOnHand['cents'],
            $this->accountsReceivable['cents'],
            $this->accountsPayable['cents'],
            $this->dashboardChartContext(),
        );
    }

    /**
     * Unified, most-recent feed across invoices, bills, cheques, payments and
     * receipts. Inflows are positive in spirit; the sign is derived from `direction`.
     *
     * @return array<int, array{label: string, date: CarbonImmutable, cents: int, direction: 'in'|'out'}>
     */
    #[Computed]
    public function recentTransactions(): array
    {
        $limit = 8;

        $invoices = Invoice::query()
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->with('contact')
            ->latest('invoice_date')->limit($limit)->get()
            ->map(fn (Invoice $i) => [
                'label' => trim('Invoice '.$i->invoice_no.' — '.($i->contact?->display_name ?? __('Customer'))),
                'date' => CarbonImmutable::parse($i->invoice_date),
                'cents' => (int) $i->total_cents,
                'direction' => 'in',
                'url' => route('invoices.show', ['company' => $this->company->slug, 'invoice' => $i->id]),
            ]);

        $bills = Bill::query()
            ->whereNotIn('status', [BillStatus::Draft->value, BillStatus::Void->value])
            ->with('contact')
            ->latest('bill_date')->limit($limit)->get()
            ->map(fn (Bill $b) => [
                'label' => trim('Bill '.$b->bill_no.' — '.($b->contact?->display_name ?? __('Vendor'))),
                'date' => CarbonImmutable::parse($b->bill_date),
                'cents' => (int) $b->total_cents,
                'direction' => 'out',
                'url' => $b->bill_type === BillType::Reimbursement
                    ? route('reimbursements.show', ['company' => $this->company->slug, 'bill' => $b->id])
                    : route('bills.show', ['company' => $this->company->slug, 'bill' => $b->id]),
            ]);

        $cheques = Cheque::query()
            ->where('status', ChequeStatus::Posted->value)
            ->with('payee')
            ->latest('cheque_date')->limit($limit)->get()
            ->map(fn (Cheque $c) => [
                'label' => trim('Cheque '.$c->cheque_no.' — '.($c->payee?->display_name ?? $c->payee_name ?? '')),
                'date' => CarbonImmutable::parse($c->cheque_date),
                'cents' => (int) $c->amount_cents,
                'direction' => 'out',
                'url' => route('cheques.show', ['company' => $this->company->slug, 'cheque' => $c->id]),
            ]);

        $payments = BillPayment::query()
            ->where('status', BillPaymentStatus::Posted->value)
            ->with('contact')
            ->latest('payment_date')->limit($limit)->get()
            ->map(fn (BillPayment $p) => [
                'label' => trim('Payment '.$p->payment_no.' — '.($p->contact?->display_name ?? __('Vendor'))),
                'date' => CarbonImmutable::parse($p->payment_date),
                'cents' => (int) $p->amount_cents,
                'direction' => 'out',
                'url' => route('bill-payments.show', ['company' => $this->company->slug, 'payment' => $p->id]),
            ]);

        $receipts = CustomerReceipt::query()
            ->where('status', ReceiptStatus::Posted->value)
            ->with('contact')
            ->latest('receipt_date')->limit($limit)->get()
            ->map(fn (CustomerReceipt $r) => [
                'label' => trim('Receipt '.$r->receipt_no.' — '.($r->contact?->display_name ?? __('Customer'))),
                'date' => CarbonImmutable::parse($r->receipt_date),
                'cents' => (int) $r->amount_cents,
                'direction' => 'in',
                'url' => route('receipts.show', ['company' => $this->company->slug, 'receipt' => $r->id]),
            ]);

        $deposits = Deposit::query()
            ->where('status', DepositStatus::Posted->value)
            ->latest('deposit_date')->limit($limit)->get()
            ->map(fn (Deposit $d) => [
                'label' => trim('Deposit '.$d->deposit_no.($d->memo ? ' — '.$d->memo : '')),
                'date' => CarbonImmutable::parse($d->deposit_date),
                'cents' => (int) $d->amount_cents,
                'direction' => 'in',
                'url' => route('deposits.show', ['company' => $this->company->slug, 'deposit' => $d->id]),
            ]);

        return Collection::make()
            ->concat($invoices)->concat($bills)->concat($cheques)
            ->concat($payments)->concat($receipts)->concat($deposits)
            ->sortByDesc(fn (array $row) => $row['date']->getTimestamp())
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * IDs of the accounts that make up "cash" — bank and undeposited funds.
     *
     * @return array<int, int>
     */
    private function cashAccountIds(): array
    {
        return Account::query()
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::UndepositedFunds->value])
            ->pluck('id')
            ->all();
    }

    /**
     * Whole-dollar string, e.g. "$248,910". Both supported currencies (CAD, USD)
     * use the dollar sign, so a bare "$" matches the rest of the UI.
     */
    public function formatWhole(int $cents): string
    {
        return '$'.number_format(round($cents / 100));
    }

    private function pctChange(int $old, int $new): ?float
    {
        if ($old === 0) {
            return null;
        }

        return round(($new - $old) / abs($old) * 100, 1);
    }
}; ?>

<section class="w-full">
    {{-- Header --}}
    <div class="mb-5">
        <p class="text-sm text-muted-foreground">{{ $company->name }} · {{ __('Financial overview') }}</p>
        <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
    </div>

    {{-- Resume the QuickBooks import if it was left unfinished --}}
    @if ($this->resumableImport)
        <div class="mb-5 flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/40 dark:bg-amber-500/10">
            <flux:icon name="arrow-down-tray" class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
            <div class="flex-1">
                <p class="font-medium text-amber-900 dark:text-amber-200">{{ __('Finish setting up your company') }}</p>
                <p class="mt-0.5 text-sm text-amber-800/80 dark:text-amber-200/70">{{ __('Your QuickBooks import is still in progress. Pick up where you left off to bring in the rest of your data.') }}</p>
                <div class="mt-3">
                    <flux:button size="sm" variant="primary" :href="route('migration.import', ['company' => $company->slug])" wire:navigate data-test="resume-import">
                        {{ __('Continue import') }}
                    </flux:button>
                </div>
            </div>
            <flux:button
                size="sm"
                variant="ghost"
                icon="x-mark"
                wire:click="dismissSetupBanner"
                wire:confirm="{{ __('Hide this reminder for good? You can still finish the import from the Import from QuickBooks page in company settings.') }}"
                :aria-label="__('Dismiss')"
                data-test="dismiss-import-banner"
            />
        </div>
    @endif

    {{-- Getting-started tips for newly created companies (self-hides when done/dismissed) --}}
    <livewire:onboarding-tips />

    {{-- Today's "Did you know?" insight (hides when none; dismissal lasts the day) --}}
    <livewire:daily-insight />

    {{-- KPI cards --}}
    @php $cardClass = 'block rounded-lg bg-muted p-4 transition hover:bg-secondary'; @endphp
    <div class="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
        {{-- Cash on hand → cash-on-hand report --}}
        <a href="{{ route('reports.cash-on-hand', ['company' => $company->slug]) }}" wire:navigate class="{{ $cardClass }}">
            <p class="text-sm text-muted-foreground">{{ __('Cash on hand') }}</p>
            <p class="mt-1.5 text-2xl font-medium tabular-nums">{{ $this->formatWhole($this->cashOnHand['cents']) }}</p>
            @php $cashPct = $this->cashOnHand['changePct']; @endphp
            @if ($cashPct !== null)
                <p class="mt-1 text-xs {{ $cashPct >= 0 ? 'text-emerald-600 dark:text-emerald-500' : 'text-red-600 dark:text-red-500' }}">
                    <flux:icon name="{{ $cashPct >= 0 ? 'arrow-up-right' : 'arrow-down-right' }}" variant="micro" class="inline align-text-bottom" />
                    {{ number_format(abs($cashPct), 1) }}%
                </p>
            @else
                <p class="mt-1 text-xs text-muted-foreground">{{ __('No prior data') }}</p>
            @endif
        </a>

        {{-- Accounts receivable → open invoices list --}}
        <a href="{{ route('reports.open-invoices', ['company' => $company->slug]) }}" wire:navigate class="{{ $cardClass }}">
            <p class="text-sm text-muted-foreground">{{ __('Accounts receivable') }}</p>
            <p class="mt-1.5 text-2xl font-medium tabular-nums">{{ $this->formatWhole($this->accountsReceivable['cents']) }}</p>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ trans_choice(':count open invoice|:count open invoices', $this->accountsReceivable['count'], ['count' => $this->accountsReceivable['count']]) }}
            </p>
        </a>

        {{-- Accounts payable → AP aging --}}
        <a href="{{ route('reports.ap-aging', ['company' => $company->slug]) }}" wire:navigate class="{{ $cardClass }}">
            <p class="text-sm text-muted-foreground">{{ __('Accounts payable') }}</p>
            <p class="mt-1.5 text-2xl font-medium tabular-nums">{{ $this->formatWhole($this->accountsPayable['cents']) }}</p>
            @if ($this->accountsPayable['dueThisWeek'] > 0)
                <p class="mt-1 text-xs text-red-600 dark:text-red-500">
                    {{ trans_choice(':count due this week|:count due this week', $this->accountsPayable['dueThisWeek'], ['count' => $this->accountsPayable['dueThisWeek']]) }}
                </p>
            @else
                <p class="mt-1 text-xs text-muted-foreground">{{ __('None due this week') }}</p>
            @endif
        </a>

        {{-- Net income MTD → income statement --}}
        <a href="{{ route('reports.income-statement', ['company' => $company->slug]) }}" wire:navigate class="{{ $cardClass }}">
            <p class="text-sm text-muted-foreground">{{ __('Net income (MTD)') }}</p>
            <p class="mt-1.5 text-2xl font-medium tabular-nums">{{ $this->formatWhole($this->netIncomeMtd['cents']) }}</p>
            @php $niPct = $this->netIncomeMtd['changePct']; @endphp
            @if ($niPct !== null)
                <p class="mt-1 text-xs {{ $niPct >= 0 ? 'text-emerald-600 dark:text-emerald-500' : 'text-red-600 dark:text-red-500' }}">
                    <flux:icon name="{{ $niPct >= 0 ? 'arrow-up-right' : 'arrow-down-right' }}" variant="micro" class="inline align-text-bottom" />
                    {{ number_format(abs($niPct), 1) }}%
                </p>
            @else
                <p class="mt-1 text-xs text-muted-foreground">{{ __('No prior data') }}</p>
            @endif
        </a>
    </div>

    {{-- Charts --}}
    <x-reports.chart-panel
        class="mb-3"
        :charts="$this->cashFlowChart()"
        :heading="__('Cash flow')"
        :title="__('Cash flow')"
        :period="__('Last 6 months')"
        :collapsible="false"
    />

    @php $hasPl = ! empty($this->plSnapshotChart()); $hasPosition = ! empty($this->positionChart()); @endphp
    @if ($hasPl || $hasPosition)
        <div class="mb-3 grid grid-cols-1 gap-3 {{ $hasPl && $hasPosition ? 'lg:grid-cols-2' : '' }}">
            @if ($hasPl)
                <x-reports.chart-panel
                    :charts="$this->plSnapshotChart()"
                    :heading="__('Income & expenses')"
                    :title="__('Income & expenses')"
                    :period="__('Fiscal year to date')"
                    :collapsible="false"
                />
            @endif
            @if ($hasPosition)
                <x-reports.chart-panel
                    :charts="$this->positionChart()"
                    :heading="__('Cash, receivables & payables')"
                    :title="__('Cash, receivables & payables')"
                    :period="__('As of today')"
                    :collapsible="false"
                />
            @endif
        </div>
    @endif

    {{-- Recent transactions --}}
    <div class="rounded-xl border border-border p-5">
        <p class="mb-3 font-medium">{{ __('Recent transactions') }}</p>
        @if (empty($this->recentTransactions))
            <p class="py-6 text-center text-sm text-muted-foreground">{{ __('No transactions yet.') }}</p>
        @else
            <div class="text-sm">
                @foreach ($this->recentTransactions as $txn)
                    <a
                        href="{{ $txn['url'] }}"
                        wire:navigate
                        class="-mx-2 flex items-center gap-3 rounded-md border-t border-border px-2 py-2.5 transition first:border-t-0 hover:bg-muted"
                    >
                        <flux:icon
                            name="{{ $txn['direction'] === 'in' ? 'arrow-down-left' : 'arrow-up-right' }}"
                            variant="micro"
                            class="shrink-0 {{ $txn['direction'] === 'in' ? 'text-emerald-600 dark:text-emerald-500' : 'text-muted-foreground' }}"
                        />
                        <span class="flex-1 truncate">{{ $txn['label'] }}</span>
                        <span class="shrink-0 text-muted-foreground">{{ $txn['date']->format('M j') }}</span>
                        <span class="w-24 shrink-0 text-right tabular-nums">{{ $txn['direction'] === 'out' ? '−' : '' }}{{ $this->formatWhole($txn['cents']) }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <x-app-footer class="mt-8 text-center text-xs" />
</section>
