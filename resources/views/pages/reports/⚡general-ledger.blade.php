<?php

use App\Concerns\HasColumnToggles;
use App\Concerns\HasReportDimensions;
use App\Models\Account;
use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\ReportCalculator;
use App\Services\Reporting\XlsxExporter;
use App\Support\Reporting\ReportDatePresets;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('General Ledger')] class extends Component
{
    use HasColumnToggles;
    use HasReportDimensions;
    use WithPagination;

    public const ALL_ACCOUNTS = 'all';

    /** Selectable on-screen page sizes; the full dataset is always exported regardless. */
    public const PAGE_SIZES = [25, 50, 100, 250];

    public Company $company;

    /** Account ID, or self::ALL_ACCOUNTS, or null. */
    #[Url(as: 'account')]
    public string $accountId = '';

    #[Url(as: 'start')]
    public string $startDate = '';

    #[Url(as: 'end')]
    public string $endDate = '';

    #[Url(as: 'per')]
    public int $perPage = 25;

    public function mount(Company $company): void
    {
        $this->company = $company;

        // Default to fiscal-year-to-date: the opening balance shows what carried
        // in, and the postings explain how the current balance built up.
        if ($this->startDate === '') {
            $this->startDate = ReportDatePresets::fiscalYearStart(
                $this->company->currentDateTime(),
                (int) ($this->company->fiscal_year_start_month ?: 1),
            )->toDateString();
        }

        if ($this->endDate === '') {
            $this->endDate = $this->company->currentDateTime()->toDateString();
        }

        if ($this->accountId === '') {
            $this->accountId = self::ALL_ACCOUNTS;
        }
    }

    /**
     * Only the single-account view's Split (contra account) column toggles; the
     * all-accounts view already shows every leg of each entry inline.
     *
     * @return array<string, string>
     */
    public function columnRegistry(): array
    {
        return ['split' => __('Split')];
    }

    /** Changing any filter or page size returns to the first page. */
    public function updated(string $property): void
    {
        if (in_array($property, ['accountId', 'startDate', 'endDate', 'perPage', 'classId', 'locationId'], true)) {
            $this->resetPage();
        }
    }

    public function isAllAccounts(): bool
    {
        return $this->accountId === self::ALL_ACCOUNTS;
    }

    /** Clamp to an allowed page size so a crafted ?per= can't load an unbounded result. */
    private function pageSize(): int
    {
        return in_array($this->perPage, self::PAGE_SIZES, true) ? $this->perPage : 25;
    }

    #[Computed]
    public function account(): ?Account
    {
        if ($this->isAllAccounts() || $this->accountId === '') {
            return null;
        }

        return Account::find((int) $this->accountId);
    }

    #[Computed]
    public function report(): ?array
    {
        $start = CarbonImmutable::parse($this->startDate);
        $end = CarbonImmutable::parse($this->endDate);

        $classId = $this->effectiveClassId();
        $locationId = $this->effectiveLocationId();

        if ($this->isAllAccounts()) {
            return app(ReportCalculator::class)->generalLedgerAllAccountsPaginated($start, $end, $this->pageSize(), $classId, $locationId);
        }

        if (! $this->account) {
            return null;
        }

        return app(ReportCalculator::class)->generalLedgerPaginated($this->account, $start, $end, $this->pageSize(), $classId, $locationId);
    }

    #[Computed]
    public function accountOptions()
    {
        return Account::query()->orderBy('code')->get(['id', 'code', 'name']);
    }

    public function exportCsv()
    {
        $start = CarbonImmutable::parse($this->startDate);
        $end = CarbonImmutable::parse($this->endDate);

        $classId = $this->effectiveClassId();
        $locationId = $this->effectiveLocationId();

        if ($this->isAllAccounts()) {
            return $this->exportAllAccountsCsv(app(ReportCalculator::class)->generalLedgerAllAccountsStreamReport($start, $end, $classId, $locationId));
        }

        if (! $this->account) {
            return null;
        }

        return $this->exportSingleAccountCsv(app(ReportCalculator::class)->generalLedgerStreamReport($this->account, $start, $end, $classId, $locationId));
    }

    public function exportXlsx()
    {
        $start = CarbonImmutable::parse($this->startDate);
        $end = CarbonImmutable::parse($this->endDate);
        $name = "general-ledger-{$this->startDate}-to-{$this->endDate}.xlsx";

        $classId = $this->effectiveClassId();
        $locationId = $this->effectiveLocationId();

        if ($this->isAllAccounts()) {
            return app(XlsxExporter::class)->generalLedgerAllAccounts(
                $name,
                $this->company,
                app(ReportCalculator::class)->generalLedgerAllAccountsStreamReport($start, $end, $classId, $locationId),
                $this->startDate,
                $this->endDate,
            );
        }

        if (! $this->account) {
            return null;
        }

        $slug = str_replace(' ', '-', strtolower($this->account->name));
        $name = "general-ledger-{$this->account->code}-{$slug}-{$this->startDate}-to-{$this->endDate}.xlsx";

        return app(XlsxExporter::class)->generalLedgerSingleAccount(
            $name,
            $this->company,
            $this->account,
            app(ReportCalculator::class)->generalLedgerStreamReport($this->account, $start, $end, $classId, $locationId),
            $this->startDate,
            $this->endDate,
        );
    }

    private function exportSingleAccountCsv(array $r)
    {
        // Yield rows lazily so the full range streams to the download without materialising.
        $rows = (function () use ($r) {
            yield ['(Opening balance)', '', '', '', '', CsvExporter::cents($r['opening'])];

            foreach ($r['lines'] as $line) {
                yield [
                    $line['date'],
                    $line['entry_no'],
                    $line['memo'],
                    CsvExporter::cents($line['debit']),
                    CsvExporter::cents($line['credit']),
                    CsvExporter::cents($line['running']),
                ];
            }

            yield ['(Closing balance)', '', '', '', '', CsvExporter::cents($r['closing'])];
        })();

        $name = str_replace(' ', '-', strtolower($this->account->name));

        return app(CsvExporter::class)->stream(
            "general-ledger-{$this->account->code}-{$name}-{$this->startDate}-{$this->endDate}.csv",
            ['Date', 'Entry #', 'Memo', 'Debit', 'Credit', 'Running balance'],
            $rows,
        );
    }

    private function exportAllAccountsCsv(array $r)
    {
        // Yield rows lazily so the full range streams to the download without materialising.
        $rows = (function () use ($r) {
            foreach ($r['entries'] as $entry) {
                foreach ($entry['lines'] as $line) {
                    yield [
                        $entry['date'],
                        $entry['entry_no'],
                        $line['account_code'],
                        $line['account_name'],
                        $line['memo'] ?? $entry['memo'],
                        CsvExporter::cents($line['debit']),
                        CsvExporter::cents($line['credit']),
                    ];
                }
            }
        })();

        return app(CsvExporter::class)->stream(
            "general-ledger-all-accounts-{$this->startDate}-{$this->endDate}.csv",
            ['Date', 'Entry #', 'Code', 'Account', 'Memo', 'Debit', 'Credit'],
            $rows,
        );
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('General Ledger') }}</flux:heading>
            <flux:subheading>
                @if ($this->isAllAccounts())
                    {{ __('All accounts, grouped by journal entry.') }}
                @else
                    {{ __('Postings for a single account with running balance.') }}
                @endif
            </flux:subheading>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @unless ($this->isAllAccounts())
                <x-reports.column-picker :columns="$this->columnRegistry()" />
            @endunless
            <flux:dropdown align="end">
                <flux:button variant="primary" icon="arrow-down-tray" icon:trailing="chevron-down" :disabled="! $this->report" data-test="gl-download">{{ __('Download') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="document-text" wire:click="exportCsv">{{ __('CSV') }}</flux:menu.item>
                    <flux:menu.item icon="table-cells" wire:click="exportXlsx" data-test="gl-export-xlsx">{{ __('Excel') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-4">
        <flux:select wire:model.live="accountId" :label="__('Account')" data-test="gl-account-select">
            <flux:select.option value="all">{{ __('— All accounts —') }}</flux:select.option>
            @foreach ($this->accountOptions as $opt)
                <flux:select.option :value="(string) $opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input type="date" wire:model.live="startDate" :label="__('Start')" />
        <flux:input type="date" wire:model.live="endDate" :label="__('End')" />
        <flux:select wire:model.live="perPage" :label="__('Rows per page')" data-test="gl-per-page">
            @foreach ([25, 50, 100, 250] as $size)
                <flux:select.option :value="$size">{{ $size }}</flux:select.option>
            @endforeach
        </flux:select>
        @if ($this->tracksClasses)
            <flux:select wire:model.live="classId" :label="__('Class')" data-test="filter-class">
                <flux:select.option value="">{{ __('All classes') }}</flux:select.option>
                @foreach ($this->classificationOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
        @if ($this->tracksLocations)
            <flux:select wire:model.live="locationId" :label="__('Location')" data-test="filter-location">
                <flux:select.option value="">{{ __('All locations') }}</flux:select.option>
                @foreach ($this->locationOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if ($this->report && $this->isAllAccounts())
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Entry #') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Code') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Memo') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Debit') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Credit') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->report['entries'] as $entry)
                        <tr class="bg-muted font-medium" data-test="gl-entry-header">
                            <td class="px-4 py-2 whitespace-nowrap">{{ $entry['date'] }}</td>
                            <td class="px-4 py-2 font-mono">
                                <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $entry['entry_no']]) }}" class="underline">{{ $entry['entry_no'] }}</a>
                            </td>
                            <td class="px-4 py-2" colspan="3">{{ $entry['memo'] }}</td>
                            <td class="px-4 py-2"></td>
                            <td class="px-4 py-2"></td>
                        </tr>
                        @foreach ($entry['lines'] as $line)
                            <tr data-test="gl-entry-line">
                                <td class="px-4 py-2"></td>
                                <td class="px-4 py-2"></td>
                                <td class="px-4 py-2 font-mono">{{ $line['account_code'] }}</td>
                                <td class="px-4 py-2">{{ $line['account_name'] }}</td>
                                <td class="px-4 py-2 text-muted-foreground">{{ $line['memo'] }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ $line['debit'] ? number_format($line['debit'] / 100, 2) : '' }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ $line['credit'] ? number_format($line['credit'] / 100, 2) : '' }}</td>
                            </tr>
                        @endforeach
                        <tr class="bg-muted text-xs">
                            <td class="px-4 py-1.5" colspan="4"></td>
                            <td class="px-4 py-1.5 text-right text-muted-foreground italic">{{ __('Entry total') }}</td>
                            <td class="px-4 py-1.5 text-right font-mono">{{ number_format($entry['total_debit'] / 100, 2) }}</td>
                            <td class="px-4 py-1.5 text-right font-mono">{{ number_format($entry['total_credit'] / 100, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-muted-foreground">{{ __('No posted entries in this range.') }}</td></tr>
                    @endforelse
                    @if ($this->report['entry_count'] > 0 && $this->report['paginator']->onLastPage())
                        <tr class="bg-muted font-semibold">
                            <td class="px-4 py-2" colspan="4"></td>
                            <td class="px-4 py-2 text-right">{{ __('Grand total') }}</td>
                            <td class="px-4 py-2 text-right font-mono" data-test="gl-grand-debit">{{ number_format($this->report['total_debit'] / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right font-mono" data-test="gl-grand-credit">{{ number_format($this->report['total_credit'] / 100, 2) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex items-center justify-between gap-4 text-sm text-muted-foreground">
            <span>{{ __(':count entries', ['count' => number_format($this->report['entry_count'])]) }}</span>
            <div>{{ $this->report['paginator']->onEachSide(1)->links() }}</div>
        </div>
    @elseif ($this->report)
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Entry #') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Memo') }}</th>
                        @if ($this->columnVisible('split'))
                            <th class="px-4 py-2 text-left">{{ __('Split') }}</th>
                        @endif
                        <th class="px-4 py-2 text-right">{{ __('Debit') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Credit') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Running') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @if ($this->report['paginator']->onFirstPage())
                        <tr class="bg-muted">
                            <td class="px-4 py-2 text-muted-foreground italic" colspan="{{ 5 + ($this->columnVisible('split') ? 1 : 0) }}">{{ __('Opening balance') }}</td>
                            <td class="px-4 py-2 text-right font-mono" data-test="gl-opening">{{ number_format($this->report['opening'] / 100, 2) }}</td>
                        </tr>
                    @else
                        <tr class="bg-muted">
                            <td class="px-4 py-2 text-muted-foreground italic" colspan="{{ 5 + ($this->columnVisible('split') ? 1 : 0) }}">{{ __('Balance brought forward') }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($this->report['page_opening'] / 100, 2) }}</td>
                        </tr>
                    @endif
                    @forelse ($this->report['lines'] as $line)
                        <tr data-test="gl-row">
                            <td class="px-4 py-2 whitespace-nowrap">{{ $line['date'] }}</td>
                            <td class="px-4 py-2 font-mono">
                                <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $line['entry_no']]) }}" class="underline">{{ $line['entry_no'] }}</a>
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">{{ $line['memo'] }}</td>
                            @if ($this->columnVisible('split'))
                                <td class="px-4 py-2 text-muted-foreground" data-test="gl-split">{{ $line['split'] }}</td>
                            @endif
                            <td class="px-4 py-2 text-right font-mono">{{ $line['debit'] ? number_format($line['debit'] / 100, 2) : '' }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ $line['credit'] ? number_format($line['credit'] / 100, 2) : '' }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($line['running'] / 100, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ 6 + ($this->columnVisible('split') ? 1 : 0) }}" class="px-4 py-6 text-center text-muted-foreground">{{ __('No activity in this range.') }}</td></tr>
                    @endforelse
                    @if ($this->report['paginator']->onLastPage())
                        <tr class="bg-muted">
                            <td class="px-4 py-2 text-muted-foreground italic" colspan="{{ 5 + ($this->columnVisible('split') ? 1 : 0) }}">{{ __('Closing balance') }}</td>
                            <td class="px-4 py-2 text-right font-mono font-semibold" data-test="gl-closing">{{ number_format($this->report['closing'] / 100, 2) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex items-center justify-between gap-4 text-sm text-muted-foreground">
            <span>{{ __(':count postings', ['count' => number_format($this->report['paginator']->total())]) }}</span>
            <div>{{ $this->report['paginator']->onEachSide(1)->links() }}</div>
        </div>
    @endif
</section>
