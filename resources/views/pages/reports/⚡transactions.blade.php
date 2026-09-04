<?php

use App\Concerns\EmailsReport;
use App\Concerns\HasColumnToggles;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportDateRange;
use App\Concerns\HasReportDimensions;
use App\Concerns\Memorizable;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use App\Support\Reporting\SourceLinkResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * QuickZoom drill target: the posted journal lines behind a figure on another
 * report. Filtered by account, contact, class/location, and date range; each
 * row links to the document that produced it via SourceLinkResolver.
 */
new #[Title('Transactions')] class extends Component {
    use EmailsReport;
    use HasColumnToggles;
    use HasCustomReportHeader;
    use HasReportDateRange;
    use HasReportDimensions;
    use Memorizable;
    use WithPagination;

    private const GROUP_OPTIONS = ['none', 'account', 'contact', 'month', 'source'];

    public Company $company;

    #[Url(as: 'account')]
    public ?int $accountId = null;

    #[Url(as: 'contact')]
    public ?int $contactId = null;

    #[Url(as: 'group')]
    public string $groupBy = 'none';

    #[Url(as: 'source')]
    public string $sourceType = '';

    /** Per-request memo for groupLabelFor(); not Livewire state. @var array<string, string> */
    protected array $groupLabelCache = [];

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportDateRange();
        $this->applyMemorized((int) request('memorized'));
        $this->sanitizeGroupBy();
        $this->sanitizeSourceType();
    }

    protected function reportKey(): string
    {
        return 'reports.transactions';
    }

    /** @return array<string, string> */
    public function columnRegistry(): array
    {
        return [
            'entry_no' => __('Entry #'),
            'name' => __('Name'),
            'memo' => __('Memo'),
        ];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['accountId', 'contactId', 'startDate', 'endDate', 'preset', 'classId', 'locationId', 'groupBy', 'sourceType'], true)) {
            $this->resetPage();
        }
    }

    public function updatedGroupBy(): void
    {
        $this->sanitizeGroupBy();
    }

    public function updatedSourceType(): void
    {
        $this->sanitizeSourceType();
    }

    private function sanitizeGroupBy(): void
    {
        if (! in_array($this->groupBy, self::GROUP_OPTIONS, true)) {
            $this->groupBy = 'none';
        }
    }

    private function sanitizeSourceType(): void
    {
        if (! in_array($this->sourceType, ['', 'journal'], true)
            && ! array_key_exists($this->sourceType, $this->sourceTypeMap())) {
            $this->sourceType = '';
        }
    }

    /**
     * class_basename => FQCN for every linkable source model — the value space
     * of the Type filter (plus '' = all types and 'journal' = manual entries).
     * Public because the view renders the options from it.
     *
     * @return array<string, class-string>
     */
    public function sourceTypeMap(): array
    {
        $map = [];

        foreach (SourceLinkResolver::sourceTypes() as $fqcn) {
            $map[class_basename($fqcn)] = $fqcn;
        }

        return $map;
    }

    #[Computed]
    public function account(): ?Account
    {
        return $this->accountId !== null ? Account::find($this->accountId) : null;
    }

    #[Computed]
    public function contactFilter(): ?Contact
    {
        return $this->contactId !== null ? Contact::find($this->contactId) : null;
    }

    /**
     * Posted lines matching the active filters, in display order. Shared by the
     * on-screen paginator and the full-dataset exports. When grouping, ordering
     * switches to group-key-first so each group's lines are contiguous.
     *
     * @return Builder<JournalLine>
     */
    private function filteredQuery(): Builder
    {
        $query = $this->baseQuery()
            ->with(['account:id,code,name', 'contact:id,display_name', 'journalEntry']);

        return match ($this->groupBy) {
            'account' => $query
                ->join('accounts as group_accounts', 'group_accounts.id', '=', 'journal_lines.account_id')
                ->select('journal_lines.*')
                ->orderBy('group_accounts.code')
                ->orderBy('journal_lines.account_id')
                ->orderBy('journal_lines.entry_date')
                ->orderBy('journal_lines.id'),
            'contact' => $query
                ->leftJoin('contacts as group_contacts', 'group_contacts.id', '=', 'journal_lines.contact_id')
                ->select('journal_lines.*')
                // (display_name IS NULL) sorts the no-contact group last on both
                // SQLite and MySQL, without COALESCE-sentinel collation surprises.
                ->orderByRaw('(group_contacts.display_name IS NULL)')
                ->orderBy('group_contacts.display_name')
                ->orderBy('journal_lines.contact_id')
                ->orderBy('journal_lines.entry_date')
                ->orderBy('journal_lines.id'),
            'source' => $query
                ->join('journal_entries as group_entries', 'group_entries.id', '=', 'journal_lines.journal_entry_id')
                ->select('journal_lines.*')
                ->orderByRaw("COALESCE(group_entries.source_type, '')")
                ->orderBy('journal_lines.entry_date')
                ->orderBy('journal_lines.id'),
            // 'month' groups by the entry_date prefix, so plain date order is
            // already grouped; 'none' keeps the original ordering.
            default => $query
                ->orderBy('journal_lines.entry_date')
                ->orderBy('journal_lines.id'),
        };
    }

    /**
     * The active filters with no eager loads or ordering — shared by the display
     * query and the group-totals aggregate so the two always agree.
     *
     * @return Builder<JournalLine>
     */
    private function baseQuery(): Builder
    {
        $sourceFqcn = $this->sourceTypeMap()[$this->sourceType] ?? null;

        return JournalLine::query()
            ->where('journal_lines.is_posted', true)
            ->whereBetween('journal_lines.entry_date', [$this->startDate, $this->endDate])
            ->when($this->accountId !== null, fn ($q) => $q->where('journal_lines.account_id', $this->accountId))
            ->when($this->contactId !== null, fn ($q) => $q->where('journal_lines.contact_id', $this->contactId))
            ->when($this->effectiveClassId() !== null, fn ($q) => $q->where('journal_lines.class_id', $this->effectiveClassId()))
            ->when($this->effectiveLocationId() !== null, fn ($q) => $q->where('journal_lines.location_id', $this->effectiveLocationId()))
            ->when($this->effectiveFundId() !== null, fn ($q) => $q->where('journal_lines.fund_id', $this->effectiveFundId()))
            ->when($this->sourceType === 'journal', fn ($q) => $q->whereHas('journalEntry', fn ($jq) => $jq->whereNull('source_type')))
            ->when($sourceFqcn !== null, fn ($q) => $q->whereHas('journalEntry', fn ($jq) => $jq->where('source_type', $sourceFqcn)));
    }

    /**
     * @return LengthAwarePaginator<int, JournalLine>
     */
    #[Computed]
    public function lines(): LengthAwarePaginator
    {
        return $this->filteredQuery()->paginate(50);
    }

    /**
     * Full per-group totals across ALL pages, via one aggregate query over the
     * same filters — a group spanning pages shows its true total on every page
     * (the same aggregate approach generalLedgerAllAccountsPaginated takes for
     * its grand totals).
     *
     * @return array<string, array{debit: int, credit: int, count: int}>
     */
    #[Computed]
    public function groupTotals(): array
    {
        if ($this->groupBy === 'none') {
            return [];
        }

        // SUBSTR(entry_date, 1, 7) works on both SQLite and MySQL: journal lines
        // store entry_date as a plain Y-m-d date (see JournalLine's creating hook).
        $keyExpr = match ($this->groupBy) {
            'account' => 'journal_lines.account_id',
            'contact' => 'journal_lines.contact_id',
            'month' => 'SUBSTR(journal_lines.entry_date, 1, 7)',
            default => 'group_entries.source_type',
        };

        $rows = $this->baseQuery()
            ->when($this->groupBy === 'source', fn ($q) => $q->join('journal_entries as group_entries', 'group_entries.id', '=', 'journal_lines.journal_entry_id'))
            ->selectRaw("{$keyExpr} AS group_key, SUM(journal_lines.debit_cents) AS debit_total, SUM(journal_lines.credit_cents) AS credit_total, COUNT(*) AS line_count")
            ->groupByRaw($keyExpr)
            ->toBase()
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) ($row->group_key ?? '')] = [
                'debit' => (int) $row->debit_total,
                'credit' => (int) $row->credit_total,
                'count' => (int) $row->line_count,
            ];
        }

        return $totals;
    }

    public function groupKeyFor(JournalLine $line): string
    {
        return match ($this->groupBy) {
            'account' => (string) $line->account_id,
            'contact' => (string) ($line->contact_id ?? ''),
            'month' => substr((string) $line->entry_date?->toDateString(), 0, 7),
            'source' => (string) ($line->journalEntry?->source_type ?? ''),
            default => '',
        };
    }

    public function groupLabelFor(string $key): string
    {
        return $this->groupLabelCache[$this->groupBy.'|'.$key] ??= $this->resolveGroupLabel($key);
    }

    private function resolveGroupLabel(string $key): string
    {
        if ($this->groupBy === 'account') {
            $account = Account::find((int) $key);

            return $account !== null ? trim(($account->code ?? '').' — '.$account->name, ' —') : $key;
        }

        return match ($this->groupBy) {
            'contact' => $key === '' ? __('(No name)') : (Contact::find((int) $key)?->display_name ?? __('(No name)')),
            'source' => $key === '' ? __('Journal entry') : class_basename($key),
            default => $key,
        };
    }

    public function sourceUrl(JournalEntry $entry): ?string
    {
        return app(SourceLinkResolver::class)->urlFor($entry, $this->company);
    }

    /**
     * Lazily yield export rows for the full filtered dataset so large ranges
     * stream to the download without materialising every line at once. When
     * grouping is on, each row gains a leading 'group' label; exports carry no
     * subtotal rows (follow-up) and all columns regardless of hidden columns.
     *
     * @return iterable<int, array{group?: string, date: string, entry_no: ?string, account: string, name: ?string, memo: ?string, debit: int, credit: int}>
     */
    private function exportRows(): iterable
    {
        $grouped = $this->groupBy !== 'none';

        foreach ($this->filteredQuery()->lazy() as $line) {
            $row = [
                'date' => (string) $line->entry_date,
                'entry_no' => $line->journalEntry?->entry_no,
                'account' => trim(($line->account?->code ?? '').' — '.($line->account?->name ?? ''), ' —'),
                'name' => $line->contact?->display_name,
                'memo' => $line->memo ?? $line->journalEntry?->memo,
                'debit' => (int) $line->debit_cents,
                'credit' => (int) $line->credit_cents,
            ];

            if ($grouped) {
                $row = ['group' => $this->groupLabelFor($this->groupKeyFor($line))] + $row;
            }

            yield $row;
        }
    }

    private function exportFilename(string $extension): string
    {
        return "transactions-{$this->startDate}-to-{$this->endDate}.{$extension}";
    }

    public function exportCsv()
    {
        $grouped = $this->groupBy !== 'none';

        $rows = (function () use ($grouped) {
            foreach ($this->exportRows() as $row) {
                $cells = [
                    $row['date'],
                    $row['entry_no'],
                    $row['account'],
                    $row['name'],
                    $row['memo'],
                    CsvExporter::cents($row['debit']),
                    CsvExporter::cents($row['credit']),
                ];

                if ($grouped) {
                    array_unshift($cells, $row['group']);
                }

                yield $cells;
            }
        })();

        $headers = ['Date', 'Entry #', 'Account', 'Name', 'Memo', 'Debit', 'Credit'];

        if ($grouped) {
            array_unshift($headers, 'Group');
        }

        return app(CsvExporter::class)->stream($this->exportFilename('csv'), $headers, $rows);
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->transactions(
            $this->exportFilename('xlsx'),
            $this->company,
            $this->exportRows(),
            $this->startDate,
            $this->endDate,
            $this->exportContext(),
            grouped: $this->groupBy !== 'none',
        );
    }

    public function exportPdf()
    {
        // The PDF export stays ungrouped (flat rows; extra 'group' keys are
        // ignored by the template) — grouping is an on-screen + CSV/XLSX concern.
        return app(PdfExporter::class)->download('pdf.reports.transactions', [
            'company' => $this->company,
            'rows' => iterator_to_array($this->exportRows()),
            'title' => $this->effectiveTitle('Transactions'),
            'period' => $this->startDate.' to '.$this->endDate,
            'context' => $this->exportContext(),
        ], $this->exportFilename('pdf'));
    }

    /** Account/contact filter summary shown in export headers, or null when unfiltered. */
    private function exportContext(): ?string
    {
        $context = collect([
            trim(($this->account?->code ?? '').' — '.($this->account?->name ?? ''), ' —'),
            $this->contactFilter?->display_name,
        ])->filter(fn ($v) => trim((string) $v) !== '')->implode(' · ');

        return $context !== '' ? $context : null;
    }
}; ?>

<section class="w-full">
    @php
        $context = collect([$this->account?->code.' — '.$this->account?->name, $this->contactFilter?->display_name])
            ->filter(fn ($v) => trim((string) $v, ' —') !== '')
            ->implode(' · ');
    @endphp

    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Transactions'))"
        :subtitle="trim($context) !== '' ? $context.' · '.$startDate.' '.__('to').' '.$endDate : $startDate.' '.__('to').' '.$endDate"
        mode="range"
        :tracks-classes="$this->tracksClasses"
        :tracks-locations="$this->tracksLocations"
        :classification-options="$this->classificationOptions"
        :location-options="$this->locationOptions"
        :exports="['csv', 'xlsx', 'pdf']"
        :title-editable="false"
        :memorizable="true"
        :emailable="$this->canEmailReport()"
        :print-url="$this->printReportUrl()"
    >
        <flux:select wire:model.live="groupBy" :label="__('Group by')" class="max-w-[170px]" data-test="group-by">
            <flux:select.option value="none">{{ __('None') }}</flux:select.option>
            <flux:select.option value="account">{{ __('Account') }}</flux:select.option>
            <flux:select.option value="contact">{{ __('Name') }}</flux:select.option>
            <flux:select.option value="month">{{ __('Month') }}</flux:select.option>
            <flux:select.option value="source">{{ __('Source type') }}</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="sourceType" :label="__('Type')" class="max-w-[180px]" data-test="filter-source-type">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            <flux:select.option value="journal">{{ __('Journal entry') }}</flux:select.option>
            @foreach (array_keys($this->sourceTypeMap()) as $basename)
                <flux:select.option value="{{ $basename }}">{{ $basename }}</flux:select.option>
            @endforeach
        </flux:select>

        <x-reports.column-picker :columns="$this->columnRegistry()" />
    </x-reports.control-bar>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    @if ($this->columnVisible('entry_no'))
                        <th class="px-4 py-2 text-left">{{ __('Entry #') }}</th>
                    @endif
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    @if ($this->columnVisible('name'))
                        <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                    @endif
                    @if ($this->columnVisible('memo'))
                        <th class="px-4 py-2 text-left">{{ __('Memo') }}</th>
                    @endif
                    <th class="px-4 py-2 text-right">{{ __('Debit') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Credit') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @php
                    $pageLines = $this->lines->items();
                    $fullSpan = $this->visibleColumnCount(fixed: 5);
                @endphp
                @forelse ($pageLines as $i => $line)
                    @php
                        $url = $this->sourceUrl($line->journalEntry);
                        $groupKey = $groupBy !== 'none' ? $this->groupKeyFor($line) : null;
                        $prevKey = $groupBy !== 'none' && $i > 0 ? $this->groupKeyFor($pageLines[$i - 1]) : null;
                        $nextKey = $groupBy !== 'none' && isset($pageLines[$i + 1]) ? $this->groupKeyFor($pageLines[$i + 1]) : null;
                    @endphp

                    @if ($groupBy !== 'none' && ($i === 0 || $groupKey !== $prevKey))
                        <tr class="bg-muted" data-test="txn-group-header">
                            <td colspan="{{ $fullSpan }}" class="px-4 py-2 font-medium">
                                {{-- Grouped by name: the header drills to that name's own transactions too. --}}
                                @if ($groupBy === 'contact' && $groupKey !== '' && (int) $groupKey !== (int) $contactId)
                                    <a href="{{ route('reports.transactions', ['company' => $company->slug, 'contact' => (int) $groupKey, 'start' => $startDate, 'end' => $endDate]) }}" wire:navigate class="hover:underline" data-test="drill-contact-group">{{ $this->groupLabelFor($groupKey) }}</a>
                                @else
                                    {{ $this->groupLabelFor($groupKey) }}
                                @endif
                            </td>
                        </tr>
                    @endif

                    <tr data-test="txn-row">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $line->entry_date }}</td>
                        @if ($this->columnVisible('entry_no'))
                            <td class="px-4 py-2 font-mono">{{ $line->journalEntry?->entry_no }}</td>
                        @endif
                        <td class="px-4 py-2">{{ $line->account?->code }} — {{ $line->account?->name }}</td>
                        @if ($this->columnVisible('name'))
                            <td class="px-4 py-2">
                                {{-- Drill to that name's own transactions, unless the report is already filtered to it. --}}
                                @if ($line->contact_id && (int) $line->contact_id !== (int) $contactId)
                                    <a href="{{ route('reports.transactions', ['company' => $company->slug, 'contact' => $line->contact_id, 'start' => $startDate, 'end' => $endDate]) }}" wire:navigate class="hover:underline" data-test="drill-contact">{{ $line->contact?->display_name }}</a>
                                @else
                                    {{ $line->contact?->display_name }}
                                @endif
                            </td>
                        @endif
                        @if ($this->columnVisible('memo'))
                            <td class="px-4 py-2 text-muted-foreground">{{ $line->memo ?? $line->journalEntry?->memo }}</td>
                        @endif
                        <td class="px-4 py-2 text-right font-mono">{{ $line->debit_cents ? number_format($line->debit_cents / 100, 2) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $line->credit_cents ? number_format($line->credit_cents / 100, 2) : '' }}</td>
                        <td class="px-4 py-2 text-right">
                            @if ($url)
                                <flux:button :href="$url" wire:navigate variant="ghost" size="xs" icon="arrow-top-right-on-square" data-test="txn-source-link">{{ __('Open') }}</flux:button>
                            @endif
                        </td>
                    </tr>

                    @if ($groupBy !== 'none' && $groupKey !== $nextKey)
                        @php $groupSum = $this->groupTotals[$groupKey] ?? ['debit' => 0, 'credit' => 0, 'count' => 0]; @endphp
                        <tr class="bg-muted/50" data-test="txn-group-subtotal">
                            <td colspan="{{ $fullSpan - 3 }}" class="px-4 py-2 text-right font-medium">
                                {{ __('Total :label (:count lines)', ['label' => $this->groupLabelFor($groupKey), 'count' => $groupSum['count']]) }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($groupSum['debit'] / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($groupSum['credit'] / 100, 2) }}</td>
                            <td class="px-4 py-2"></td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="{{ $fullSpan }}" class="px-4 py-6 text-center text-muted-foreground">{{ __('No transactions match these filters.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->lines->links() }}
    </div>
</section>
