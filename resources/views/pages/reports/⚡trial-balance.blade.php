<?php

use App\Concerns\EmailsReport;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportAsOfDate;
use App\Concerns\HasReportNumberFormat;
use App\Concerns\Memorizable;
use App\Enums\NormalBalance;
use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\ReportCalculator;
use App\Services\Reporting\XlsxExporter;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Trial Balance')] class extends Component {
    use EmailsReport;
    use HasCustomReportHeader;
    use HasReportAsOfDate;
    use HasReportNumberFormat;
    use Memorizable;

    public Company $company;

    #[Url(as: 'type')]
    public string $accountType = '';

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportAsOfDate();
        $this->applyMemorized((int) request('memorized'));
    }

    protected function reportKey(): string
    {
        return 'reports.trial-balance';
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
     * @return array{rows: array<int, array{id: int, code: string, name: string, type: string, debit: int, credit: int}>, totals: array{debit: int, credit: int}}
     */
    #[Computed]
    public function report(): array
    {
        $asOf = CarbonImmutable::parse($this->asOf);
        $tb = app(ReportCalculator::class)->trialBalance($this->company, $asOf);

        $rows = [];
        $totalDr = 0;
        $totalCr = 0;

        foreach ($tb as $row) {
            $account = $row['account'];
            $balance = $row['balance'];

            if ($this->accountType !== '' && $account->type->value !== $this->accountType) {
                continue;
            }

            if ($account->normal_balance === NormalBalance::Debit) {
                $debit = $balance > 0 ? $balance : 0;
                $credit = $balance < 0 ? -$balance : 0;
            } else {
                $credit = $balance > 0 ? $balance : 0;
                $debit = $balance < 0 ? -$balance : 0;
            }

            $rows[] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->label(),
                'debit' => $debit,
                'credit' => $credit,
            ];

            $totalDr += $debit;
            $totalCr += $credit;
        }

        return ['rows' => $rows, 'totals' => ['debit' => $totalDr, 'credit' => $totalCr]];
    }

    public function exportCsv()
    {
        $r = $this->report;

        return app(CsvExporter::class)->stream(
            'trial-balance-'.$this->asOf.'.csv',
            ['Code', 'Account', 'Type', 'Debit', 'Credit'],
            collect($r['rows'])->map(fn ($row) => [
                $row['code'], $row['name'], $row['type'],
                CsvExporter::cents($row['debit']),
                CsvExporter::cents($row['credit']),
            ])->push(['', 'TOTAL', '', CsvExporter::cents($r['totals']['debit']), CsvExporter::cents($r['totals']['credit'])]),
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->trialBalance(
            "trial-balance-{$this->asOf}.xlsx",
            $this->company,
            $this->report,
            $this->asOf,
            $this->numberFormat->xlsxMoneyFormat(),
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.trial-balance', [
            'company' => $this->company,
            'report' => $this->report,
            'asOf' => $this->asOf,
            'title' => $this->effectiveTitle('Trial Balance'),
            'fmt' => $this->numberFormat,
        ], "trial-balance-{$this->asOf}.pdf");
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Trial Balance'))"
        :subtitle="__('All accounts with non-zero balances at a point in time.').($this->numberFormat->unitsSuffix() ?? '')"
        mode="single"
        :number-format="true"
        :title-editable="true"
        :memorizable="true"
        :emailable="$this->canEmailReport()"
        :print-url="$this->printReportUrl()"
    >
        <flux:select wire:model.live="accountType" :label="__('Type')" class="max-w-[180px]" data-test="filter-account-type">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            @foreach (\App\Enums\AccountType::cases() as $type)
                <flux:select.option value="{{ $type->value }}">{{ __($type->label()) }}</flux:select.option>
            @endforeach
        </flux:select>
    </x-reports.control-bar>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Code') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Debit') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Credit') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @php
                    $fmt = $this->numberFormat;
                    $drillStart = $this->fiscalYearStartFor(\Carbon\CarbonImmutable::parse($asOf))->toDateString();
                @endphp
                @forelse ($this->report['rows'] as $row)
                    @php
                        $drillUrl = route('reports.general-ledger', [
                            'company' => $company->slug,
                            'account' => $row['id'],
                            'start' => $drillStart,
                            'end' => $asOf,
                        ]);
                    @endphp
                    <tr data-test="tb-row">
                        <td class="px-4 py-2 font-mono">
                            <a href="{{ $drillUrl }}" class="underline" data-test="tb-account-link">{{ $row['code'] }}</a>
                        </td>
                        <td class="px-4 py-2">
                            <a href="{{ $drillUrl }}" class="underline">{{ $row['name'] }}</a>
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['type'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $row['debit'] ? $fmt->format($row['debit']) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $row['credit'] ? $fmt->format($row['credit']) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No activity through this date.') }}</td></tr>
                @endforelse
            </tbody>
            <tfoot class="bg-muted">
                <tr class="text-base">
                    <td colspan="3" class="px-4 py-2 text-right font-semibold">{{ __('Totals') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold" data-test="tb-total-dr">{{ $this->numberFormat->format($this->report['totals']['debit']) }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold" data-test="tb-total-cr">{{ $this->numberFormat->format($this->report['totals']['credit']) }}</td>
                </tr>
                @if ($accountType === '' && $this->report['totals']['debit'] !== $this->report['totals']['credit'])
                    <tr><td colspan="5" class="px-4 py-2 text-right text-red-600">{{ __('Trial balance is out of balance — this should never happen!') }}</td></tr>
                @endif
            </tfoot>
        </table>
    </div>
</section>
