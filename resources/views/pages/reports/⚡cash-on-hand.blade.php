<?php

use App\Concerns\EmailsReport;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportAsOfDate;
use App\Concerns\Memorizable;
use App\Models\Account;
use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\FinancialMetrics;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\ReportCalculator;
use App\Services\Reporting\XlsxExporter;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cash on Hand')] class extends Component {
    use EmailsReport;
    use HasCustomReportHeader;
    use HasReportAsOfDate;
    use Memorizable;

    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportAsOfDate();
        $this->applyMemorized((int) request('memorized'));
    }

    protected function reportKey(): string
    {
        return 'reports.cash-on-hand';
    }

    /**
     * Fiscal-year start date for the period containing $asOf — used as the
     * default `start` when drilling through to the General Ledger so the
     * user sees year-to-date activity that built up the balance.
     */
    private function fiscalYearStartFor(CarbonImmutable $asOf): CarbonImmutable
    {
        $startMonth = (int) ($this->company->fiscal_year_start_month ?? 1);
        $year = $asOf->month >= $startMonth ? $asOf->year : $asOf->year - 1;

        return CarbonImmutable::create($year, $startMonth, 1);
    }

    /**
     * Every account behind the dashboard's Cash on hand figure, including
     * inactive ones — dropping any row would make the total stop reconciling
     * with the card.
     *
     * @return array<int, array{id: int, code: string, name: string, subtype: string, balance: int, active: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        $asOf = CarbonImmutable::parse($this->asOf);
        $calculator = app(ReportCalculator::class);

        return Account::query()
            ->whereIn('subtype', array_map(fn ($subtype) => $subtype->value, FinancialMetrics::CASH_SUBTYPES))
            ->orderBy('code')
            ->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'subtype' => $account->subtype->label(),
                'balance' => $calculator->balanceAsOf($account, $asOf),
                'active' => (bool) $account->is_active,
            ])
            ->all();
    }

    #[Computed]
    public function total(): int
    {
        return array_sum(array_column($this->rows, 'balance'));
    }

    public function exportCsv()
    {
        return app(CsvExporter::class)->stream(
            'cash-on-hand-'.$this->asOf.'.csv',
            ['Code', 'Account', 'Subtype', 'Balance'],
            collect($this->rows)->map(fn (array $row) => [
                $row['code'], $row['name'], $row['subtype'],
                CsvExporter::cents($row['balance']),
            ])->push(['', 'TOTAL', '', CsvExporter::cents($this->total)]),
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->listTable(
            "cash-on-hand-{$this->asOf}.xlsx",
            'Cash on Hand',
            $this->effectiveTitle('Cash on Hand'),
            $this->company,
            ['As of '.$this->asOf],
            ['Code', 'Account', 'Subtype', 'Balance'],
            collect($this->rows)->map(fn (array $row) => [
                $row['code'], $row['name'], $row['subtype'], $row['balance'],
            ])->push(['', 'TOTAL', '', $this->total])->all(),
            moneyColumns: [4],
            columnWidths: [1 => 10, 2 => 38, 3 => 20, 4 => 16],
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.list-table', [
            'company' => $this->company,
            'title' => $this->effectiveTitle('Cash on Hand'),
            'period' => 'as of '.$this->asOf,
            'headers' => [
                ['label' => 'Code'], ['label' => 'Account'], ['label' => 'Subtype'],
                ['label' => 'Balance', 'num' => true],
            ],
            'rows' => collect($this->rows)->map(fn (array $row) => [
                ['value' => $row['code']],
                ['value' => $row['name']],
                ['value' => $row['subtype']],
                ['value' => number_format($row['balance'] / 100, 2), 'num' => true],
            ])->push([
                ['value' => ''],
                ['value' => 'TOTAL'],
                ['value' => ''],
                ['value' => number_format($this->total / 100, 2), 'num' => true],
            ])->all(),
            'emptyMessage' => 'No bank or undeposited-funds accounts.',
        ], "cash-on-hand-{$this->asOf}.pdf");
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Cash on Hand'))"
        :subtitle="__('Every bank and undeposited-funds account that makes up your cash balance.')"
        mode="single"
        :exports="['csv', 'xlsx', 'pdf']"
        :title-editable="true"
        :memorizable="true"
        :emailable="$this->canEmailReport()"
        :print-url="$this->printReportUrl()"
    />

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Code') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Subtype') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @php
                    $drillStart = $this->fiscalYearStartFor(\Carbon\CarbonImmutable::parse($asOf))->toDateString();
                @endphp
                @forelse ($this->rows as $row)
                    @php
                        $drillUrl = route('reports.general-ledger', [
                            'company' => $company->slug,
                            'account' => $row['id'],
                            'start' => $drillStart,
                            'end' => $asOf,
                        ]);
                    @endphp
                    <tr class="@if(! $row['active']) opacity-50 @endif" data-test="cash-row">
                        <td class="px-4 py-2 font-mono">
                            <a href="{{ $drillUrl }}" class="underline" data-test="cash-account-link">{{ $row['code'] }}</a>
                        </td>
                        <td class="px-4 py-2">
                            <a href="{{ $drillUrl }}" class="underline">{{ $row['name'] }}</a>
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['subtype'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($row['balance'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-muted-foreground">{{ __('No bank or undeposited-funds accounts.') }}</td></tr>
                @endforelse
            </tbody>
            @if (count($this->rows))
                <tfoot class="bg-muted">
                    <tr>
                        <td class="px-4 py-2"></td>
                        <td class="px-4 py-2 font-medium">{{ __('Total') }}</td>
                        <td class="px-4 py-2"></td>
                        <td class="px-4 py-2 text-right font-mono font-medium" data-test="cash-total">{{ number_format($this->total / 100, 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</section>
