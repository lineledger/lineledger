<?php

use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportDateRange;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\VendorActivityBuilder;
use App\Services\Reporting\XlsxExporter;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every posted transaction with a vendor — the vendor-list Name drill. Unlike
 * the AP statement it includes cheques and expenses coded straight to an
 * expense account, which never touch Accounts Payable. See VendorActivityBuilder.
 */
new #[Title('Vendor Activity')] class extends Component {
    use HasCustomReportHeader;
    use HasReportDateRange;
    use WithPagination;

    public Company $company;

    #[Url(as: 'contact')]
    public ?int $contactId = null;

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportDateRange();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['contactId', 'startDate', 'endDate', 'preset'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Vendors for the picker: the active ones, plus the selected vendor even
     * when inactive so a deep link still shows who is selected.
     *
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function vendorOptions(): Collection
    {
        return Contact::query()
            ->where('is_vendor', true)
            ->where(fn ($q) => $q->where('is_active', true)->when($this->contactId, fn ($q) => $q->orWhere('id', $this->contactId)))
            ->orderBy('display_name')
            ->get(['id', 'display_name']);
    }

    #[Computed]
    public function vendor(): ?Contact
    {
        return $this->contactId ? Contact::find($this->contactId) : null;
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return app(VendorActivityBuilder::class)
            ->query($this->company, CarbonImmutable::parse($this->startDate), CarbonImmutable::parse($this->endDate), $this->contactId ?: null)
            ->paginate(50);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        return app(VendorActivityBuilder::class)->hydrate($this->company, $this->entries->getCollection());
    }

    public function activityUrl(int $contactId): string
    {
        return route('reports.vendor-activity', [
            'company' => $this->company->slug,
            'contact' => $contactId,
            'range' => $this->preset,
            'start' => $this->startDate,
            'end' => $this->endDate,
        ]);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function exportRows(): iterable
    {
        return app(VendorActivityBuilder::class)->rows(
            $this->company,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
            $this->contactId ?: null,
        );
    }

    private function exportFilename(string $extension): string
    {
        return "vendor-activity-{$this->startDate}-to-{$this->endDate}.{$extension}";
    }

    /** @return list<string> */
    private function exportMetaLines(): array
    {
        return array_values(array_filter([$this->vendor?->display_name, $this->startDate.' '.__('to').' '.$this->endDate]));
    }

    /** @param array<string, mixed> $row */
    private static function splitText(array $row): string
    {
        return implode('; ', $row['split']);
    }

    public function exportCsv()
    {
        $rows = (function () {
            foreach ($this->exportRows() as $row) {
                yield [
                    $row['vendor'], $row['date'], $row['type'], $row['doc_no'], $row['memo'],
                    $row['account'], self::splitText($row), CsvExporter::cents($row['amount']), $row['is_void'] ? __('Void') : '',
                ];
            }
        })();

        return app(CsvExporter::class)->stream(
            $this->exportFilename('csv'),
            [__('Vendor'), __('Date'), __('Type'), __('No.'), __('Memo'), __('Account'), __('Split'), __('Amount'), __('Status')],
            $rows,
        );
    }

    public function exportXlsx()
    {
        $rows = (function () {
            foreach ($this->exportRows() as $row) {
                yield [
                    $row['vendor'], $row['date'], $row['type'], $row['doc_no'], $row['memo'],
                    $row['account'], self::splitText($row), $row['amount'], $row['is_void'] ? __('Void') : '',
                ];
            }
        })();

        return app(XlsxExporter::class)->listTable(
            $this->exportFilename('xlsx'),
            __('Vendor Activity'),
            $this->effectiveTitle(__('Vendor Activity')),
            $this->company,
            $this->exportMetaLines(),
            [__('Vendor'), __('Date'), __('Type'), __('No.'), __('Memo'), __('Account'), __('Split'), __('Amount'), __('Status')],
            $rows,
            moneyColumns: [8],
            columnWidths: [1 => 28, 2 => 12, 3 => 14, 4 => 14, 5 => 36, 6 => 30, 7 => 36, 8 => 14, 9 => 8],
        );
    }

    public function exportPdf()
    {
        $rows = [];

        foreach ($this->exportRows() as $row) {
            $rows[] = [
                ['value' => $row['vendor']],
                ['value' => $row['date']],
                ['value' => $row['type'].($row['is_void'] ? ' ('.__('void').')' : '')],
                ['value' => $row['doc_no']],
                ['value' => $row['memo']],
                ['value' => $row['account']],
                ['value' => self::splitText($row)],
                ['value' => number_format($row['amount'] / 100, 2), 'num' => true],
            ];
        }

        return app(PdfExporter::class)->download('pdf.reports.list-table', [
            'company' => $this->company,
            'title' => $this->effectiveTitle(__('Vendor Activity')),
            'period' => implode(' · ', $this->exportMetaLines()),
            'headers' => [
                ['label' => __('Vendor')], ['label' => __('Date')], ['label' => __('Type')], ['label' => __('No.')],
                ['label' => __('Memo')], ['label' => __('Account')], ['label' => __('Split')], ['label' => __('Amount'), 'num' => true],
            ],
            'rows' => $rows,
            'emptyMessage' => __('No vendor transactions in this period.'),
        ], $this->exportFilename('pdf'));
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Vendor Activity'))"
        :subtitle="($this->vendor?->display_name ?? $company->name).' · '.$startDate.' '.__('to').' '.$endDate"
        mode="range"
        :exports="['csv', 'xlsx', 'pdf']"
        :exports-disabled="$this->entries->total() === 0"
    >
        <flux:select wire:model.live="contactId" :label="__('Vendor')" class="max-w-[220px]" data-test="filter-vendor">
            <flux:select.option value="">{{ __('All vendors') }}</flux:select.option>
            @foreach ($this->vendorOptions as $option)
                <flux:select.option :value="$option->id">{{ $option->display_name }}</flux:select.option>
            @endforeach
        </flux:select>
    </x-reports.control-bar>

    @if ($this->vendor)
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <flux:button
                size="sm"
                variant="ghost"
                icon="document-text"
                :href="route('reports.contact-statement', ['company' => $company->slug, 'contact' => $this->vendor->id, 'kind' => 'ap'])"
                wire:navigate
                data-test="vendor-activity-statement-link"
            >{{ __('AP statement') }}</flux:button>
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('No.') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Memo') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Split') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $i => $row)
                    @if (! $this->vendor && ($i === 0 || $this->rows[$i - 1]['contact_id'] !== $row['contact_id']))
                        <tr class="bg-muted/50" data-test="vendor-activity-group">
                            <td colspan="8" class="px-4 py-2 font-semibold">
                                <a href="{{ $this->activityUrl($row['contact_id']) }}" wire:navigate class="hover:underline">{{ $row['vendor'] }}</a>
                            </td>
                        </tr>
                    @endif

                    <tr data-test="vendor-activity-row" wire:key="va-{{ $row['contact_id'] }}-{{ $row['entry_id'] }}" @class(['text-muted-foreground' => $row['is_void']])>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $row['date'] }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ $row['type'] }}
                            @if ($row['is_void'])
                                <flux:badge size="sm" color="zinc" class="ml-1" data-test="vendor-activity-void">{{ __('Void') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 font-mono">{{ $row['doc_no'] }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['memo'] }}</td>
                        <td class="px-4 py-2">{{ $row['account'] }}</td>
                        <td class="px-4 py-2">
                            @if (count($row['split']) > 0)
                                <span title="{{ implode("\n", $row['split']) }}">
                                    {{ $row['split'][0] }}
                                    @if (count($row['split']) > 1)
                                        <span class="text-xs text-muted-foreground">{{ __('+ :count more', ['count' => count($row['split']) - 1]) }}</span>
                                    @endif
                                </span>
                            @endif
                        </td>
                        <td @class(['px-4 py-2 text-right font-mono whitespace-nowrap', 'line-through' => $row['is_void']])>{{ number_format($row['amount'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right">
                            @if ($row['url'])
                                <flux:button :href="$row['url']" wire:navigate variant="ghost" size="xs" icon="arrow-top-right-on-square" data-test="vendor-activity-open">{{ __('Open') }}</flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-muted-foreground">{{ __('No vendor transactions in this period.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->entries->links() }}</div>
</section>
