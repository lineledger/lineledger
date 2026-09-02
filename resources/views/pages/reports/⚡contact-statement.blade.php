<?php

use App\Enums\AccountSubtype;
use App\Enums\Section;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Reporting\ContactStatementBuilder;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Account Statement')] class extends Component
{
    public Company $company;

    public Contact $contact;

    /** 'ar' or 'ap' */
    #[Url(as: 'kind')]
    public string $kind = '';

    #[Url(as: 'start')]
    public string $startDate = '';

    #[Url(as: 'end')]
    public string $endDate = '';

    public function mount(Company $company, Contact $contact): void
    {
        $this->company = $company;
        $this->contact = $contact;

        if ($this->kind === '' || ! in_array($this->kind, ['ar', 'ap'], true)) {
            $this->kind = $contact->is_customer ? 'ar' : ($contact->is_vendor ? 'ap' : 'ar');
        }

        if ($this->startDate === '') {
            $this->startDate = $this->company->currentDateTime()->startOfYear()->toDateString();
        }

        if ($this->endDate === '') {
            $this->endDate = $this->company->currentDateTime()->toDateString();
        }
    }

    public function isAr(): bool
    {
        return $this->kind === 'ar';
    }

    /**
     * The statement modal and the edit link lead into the Customers/Vendors
     * section, which a Reports-only member can't use — so only offer them
     * when the viewer can actually get there.
     */
    #[Computed]
    public function canManageContact(): bool
    {
        return (bool) Auth::user()?->canAccessSection(
            $this->company,
            $this->isAr() ? Section::Customers : Section::Vendors,
        );
    }

    #[Computed]
    public function editContactUrl(): string
    {
        return route($this->isAr() ? 'customers.index' : 'vendors.index', [
            'company' => $this->company->slug,
            'edit' => $this->contact->id,
        ]);
    }

    /**
     * @return array{
     *     opening: int,
     *     lines: array<int, array{date: string, doc_no: string, type: string, memo: string, debit: int, credit: int, running: int, route_name: string, route_param: string}>,
     *     period_debit: int,
     *     period_credit: int,
     *     closing: int,
     * }
     */
    #[Computed]
    public function report(): array
    {
        return app(ContactStatementBuilder::class)->build(
            $this->company,
            $this->contact,
            $this->isAr() ? AccountSubtype::AccountsReceivable : AccountSubtype::AccountsPayable,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
        );
    }

    private function filenameStem(): string
    {
        $slug = str_replace(' ', '-', strtolower(preg_replace('/[^A-Za-z0-9 ]+/', '', $this->contact->display_name)));
        $slug = trim($slug, '-') ?: 'contact-'.$this->contact->id;
        $prefix = $this->isAr() ? 'ar-statement' : 'ap-statement';

        return "{$prefix}-{$slug}-{$this->startDate}-to-{$this->endDate}";
    }

    public function exportCsv()
    {
        $r = $this->report;

        $rows = collect();
        $rows->push(['(Opening balance)', '', '', '', '', '', CsvExporter::cents($r['opening'])]);

        foreach ($r['lines'] as $line) {
            $rows->push([
                $line['date'],
                $line['type'],
                $line['doc_no'],
                $line['memo'],
                $line['debit'] ? CsvExporter::cents($line['debit']) : '',
                $line['credit'] ? CsvExporter::cents($line['credit']) : '',
                CsvExporter::cents($line['running']),
            ]);
        }

        $rows->push(['(Closing balance)', '', '', '', CsvExporter::cents($r['period_debit']), CsvExporter::cents($r['period_credit']), CsvExporter::cents($r['closing'])]);

        return app(CsvExporter::class)->stream(
            $this->filenameStem().'.csv',
            ['Date', 'Type', 'Doc #', 'Memo', 'Debit', 'Credit', 'Running'],
            $rows,
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->contactStatement(
            $this->filenameStem().'.xlsx',
            $this->isAr() ? 'AR Statement' : 'AP Statement',
            $this->company,
            $this->contact,
            $this->report,
            $this->startDate,
            $this->endDate,
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.contact-statement', [
            'company' => $this->company,
            'contact' => $this->contact,
            'title' => $this->isAr() ? 'AR Statement' : 'AP Statement',
            'report' => $this->report,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ], $this->filenameStem().'.pdf');
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">
                {{ $this->isAr() ? __('AR Statement') : __('AP Statement') }}
            </flux:heading>
            <flux:subheading>
                {{ $contact->display_name }}
                @if ($this->isAr())
                    — {{ __('Customer transactions in the selected period.') }}
                @else
                    — {{ __('Vendor transactions in the selected period.') }}
                @endif
            </flux:subheading>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            @if ($this->isAr() && $this->canManageContact)
                {{-- The child modal isn't re-rendered with this page, so the dates travel with the event. --}}
                <flux:button
                    icon="document-text"
                    wire:click="$dispatch('open-customer-statement', { id: {{ $contact->id }}, start: $wire.startDate, end: $wire.endDate })"
                    data-test="statement-open-modal"
                >{{ __('Statement…') }}</flux:button>
            @endif
            <flux:dropdown align="end">
                <flux:button variant="primary" icon="arrow-down-tray" icon:trailing="chevron-down" data-test="statement-download">{{ __('Download') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="document-text" wire:click="exportCsv">{{ __('CSV') }}</flux:menu.item>
                    <flux:menu.item icon="table-cells" wire:click="exportXlsx">{{ __('Excel') }}</flux:menu.item>
                    <flux:menu.item icon="document" wire:click="exportPdf">{{ __('PDF') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-2">
        <flux:button
            size="sm"
            :href="route('reports.'.($this->isAr() ? 'ar-aging' : 'ap-aging'), ['company' => $company->slug])"
            icon="arrow-left"
            variant="ghost"
        >
            {{ $this->isAr() ? __('Back to AR Aging') : __('Back to AP Aging') }}
        </flux:button>

        @if ($this->canManageContact)
            <flux:button size="sm" variant="ghost" icon="pencil" :href="$this->editContactUrl" data-test="statement-edit-contact">
                {{ $this->isAr() ? __('Edit customer') : __('Edit vendor') }}
            </flux:button>
        @endif

        <div class="grow"></div>

        <flux:input type="date" wire:model.live="startDate" :label="__('Start')" class="max-w-[180px]" />
        <flux:input type="date" wire:model.live="endDate" :label="__('End')" class="max-w-[180px]" />
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Doc #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Memo') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Debit') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Credit') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Running') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <tr class="bg-muted">
                    <td class="px-4 py-2 text-muted-foreground italic" colspan="6">{{ __('Opening balance') }}</td>
                    <td class="px-4 py-2 text-right font-mono" data-test="statement-opening">{{ number_format($this->report['opening'] / 100, 2) }}</td>
                </tr>
                @forelse ($this->report['lines'] as $line)
                    <tr data-test="statement-row">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $line['date'] }}</td>
                        <td class="px-4 py-2">{{ $line['type'] }}</td>
                        <td class="px-4 py-2 font-mono">
                            <a
                                href="{{ route($line['route_name'], ['company' => $company->slug, $line['route_param'] => $line['route_value']]) }}"
                                class="underline"
                            >{{ $line['doc_no'] }}</a>
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $line['memo'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $line['debit'] ? number_format($line['debit'] / 100, 2) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $line['credit'] ? number_format($line['credit'] / 100, 2) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line['running'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ __('No transactions in this range.') }}</td></tr>
                @endforelse
                <tr class="bg-muted text-xs">
                    <td class="px-4 py-1.5" colspan="4"></td>
                    <td class="px-4 py-1.5 text-right text-muted-foreground italic">{{ __('Period totals') }}</td>
                    <td class="px-4 py-1.5 text-right font-mono">{{ number_format($this->report['period_debit'] / 100, 2) }}</td>
                    <td class="px-4 py-1.5 text-right font-mono">{{ number_format($this->report['period_credit'] / 100, 2) }}</td>
                </tr>
                <tr class="bg-muted">
                    <td class="px-4 py-2 text-right font-semibold" colspan="6">{{ __('Closing balance') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold" data-test="statement-closing">{{ number_format($this->report['closing'] / 100, 2) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    @if ($this->isAr() && $this->canManageContact)
        <livewire:customer-statement-modal :company="$company" />
    @endif
</section>
