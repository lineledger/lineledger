<?php

use App\Concerns\EmailsReport;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportAsOfDate;
use App\Concerns\Memorizable;
use App\Models\Account;
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

new #[Title('Account List')] class extends Component {
    use EmailsReport;
    use HasCustomReportHeader;
    use HasReportAsOfDate;
    use Memorizable;

    private const SORT_FIELDS = ['code', 'name', 'type', 'balance'];

    public Company $company;

    #[Url(as: 'inactive')]
    public bool $includeInactive = false;

    #[Url(as: 'sort')]
    public string $sortField = 'code';

    #[Url(as: 'dir')]
    public string $sortDir = 'asc';

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportAsOfDate();
        $this->applyMemorized((int) request('memorized'));
    }

    protected function reportKey(): string
    {
        return 'reports.account-list';
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORT_FIELDS, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = 'asc';
        }
    }

    /**
     * @return array<int, array{id: int, code: string, name: string, type: string, subtype: string, currency: string, balance: int, active: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        $asOf = CarbonImmutable::parse($this->asOf);
        $calculator = app(ReportCalculator::class);

        $rows = Account::query()
            ->when(! $this->includeInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->label(),
                'subtype' => $account->subtype->label(),
                'currency' => $account->currency_code ?? $this->company->currency_code,
                'balance' => $calculator->reportingBalanceAsOf($this->company, $account, $asOf),
                'active' => (bool) $account->is_active,
            ])
            ->all();

        $field = $this->sortField;
        $dirMul = $this->sortDir === 'desc' ? -1 : 1;

        usort($rows, function (array $a, array $b) use ($field, $dirMul): int {
            $cmp = $field === 'balance'
                ? ($a['balance'] <=> $b['balance'])
                : strcasecmp($a[$field], $b[$field]);

            return $cmp === 0
                ? strcasecmp($a['code'], $b['code'])
                : $cmp * $dirMul;
        });

        return $rows;
    }

    public function exportCsv()
    {
        return app(CsvExporter::class)->stream(
            'account-list-'.$this->asOf.'.csv',
            ['Code', 'Name', 'Type', 'Subtype', 'Currency', 'Balance', 'Active'],
            collect($this->rows)->map(fn (array $row) => [
                $row['code'], $row['name'], $row['type'], $row['subtype'], $row['currency'],
                CsvExporter::cents($row['balance']),
                $row['active'] ? 'Yes' : 'No',
            ]),
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->listTable(
            "account-list-{$this->asOf}.xlsx",
            'Account List',
            $this->effectiveTitle('Account List'),
            $this->company,
            ['As of '.$this->asOf],
            ['Code', 'Name', 'Type', 'Subtype', 'Currency', 'Balance', 'Active'],
            collect($this->rows)->map(fn (array $row) => [
                $row['code'], $row['name'], $row['type'], $row['subtype'], $row['currency'],
                $row['balance'],
                $row['active'] ? 'Yes' : 'No',
            ])->all(),
            moneyColumns: [6],
            columnWidths: [1 => 10, 2 => 38, 3 => 16, 4 => 24, 5 => 10, 6 => 16, 7 => 8],
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.list-table', [
            'company' => $this->company,
            'title' => $this->effectiveTitle('Account List'),
            'period' => 'as of '.$this->asOf,
            'headers' => [
                ['label' => 'Code'], ['label' => 'Name'], ['label' => 'Type'], ['label' => 'Subtype'],
                ['label' => 'Currency'], ['label' => 'Balance', 'num' => true], ['label' => 'Active'],
            ],
            'rows' => collect($this->rows)->map(fn (array $row) => [
                ['value' => $row['code']],
                ['value' => $row['name']],
                ['value' => $row['type']],
                ['value' => $row['subtype']],
                ['value' => $row['currency']],
                ['value' => number_format($row['balance'] / 100, 2), 'num' => true],
                ['value' => $row['active'] ? 'Yes' : 'No'],
            ])->all(),
            'emptyMessage' => 'No accounts to list.',
        ], "account-list-{$this->asOf}.pdf");
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Account List'))"
        :subtitle="__('Every account in the chart with its type, currency, and balance.')"
        mode="single"
        :exports="['csv', 'xlsx', 'pdf']"
        :title-editable="true"
        :memorizable="true"
        :emailable="$this->canEmailReport()"
        :print-url="$this->printReportUrl()"
    >
        <flux:switch wire:model.live="includeInactive" :label="__('Include inactive')" data-test="include-inactive-toggle" />
    </x-reports.control-bar>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left"><x-sort-header field="code" :current-field="$sortField" :current-dir="$sortDir" :label="__('Code')" /></th>
                    <th class="px-4 py-2 text-left"><x-sort-header field="name" :current-field="$sortField" :current-dir="$sortDir" :label="__('Name')" /></th>
                    <th class="px-4 py-2 text-left"><x-sort-header field="type" :current-field="$sortField" :current-dir="$sortDir" :label="__('Type')" /></th>
                    <th class="px-4 py-2 text-left">{{ __('Subtype') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Currency') }}</th>
                    <th class="px-4 py-2 text-right"><x-sort-header field="balance" :current-field="$sortField" :current-dir="$sortDir" :label="__('Balance')" align="right" /></th>
                    <th class="px-4 py-2 text-left">{{ __('Active') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $row)
                    <tr class="@if(! $row['active']) opacity-50 @endif" data-test="account-list-row">
                        <td class="px-4 py-2 font-mono">{{ $row['code'] }}</td>
                        <td class="px-4 py-2">{{ $row['name'] }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['type'] }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['subtype'] }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['currency'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($row['balance'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['active'] ? __('Yes') : __('No') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ __('No accounts to list.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
