<?php

use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportDateRange;
use App\Concerns\Memorizable;
use App\Models\Company;
use App\Models\Partner;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\GifiStatementBuilder;
use App\Services\Reporting\PdfExporter;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('T5013 Partnership')] class extends Component {
    use HasCustomReportHeader;
    use HasReportDateRange;
    use Memorizable;

    public Company $company;

    public string $partnerName = '';

    public string $partnerShare = '';

    public function mount(Company $company): void
    {
        abort_unless($company->filesT5013(), 404);

        $this->company = $company;
        $this->initReportDateRange('this_fiscal_year');
        $this->applyMemorized((int) request('memorized'));
    }

    protected function reportKey(): string
    {
        return 'reports.t5013';
    }

    #[Computed]
    public function report(): array
    {
        return app(GifiStatementBuilder::class)->build(
            $this->company,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, Partner>
     */
    #[Computed]
    public function partners()
    {
        return Partner::query()->where('company_id', $this->company->id)->orderBy('id')->get();
    }

    /**
     * Net income allocated across partners by ownership share. Allocations are
     * cents-exact: the last partner absorbs any rounding remainder so the total
     * ties to net income.
     *
     * @return array{rows: list<array{name: string, share_bps: int, amount: int}>, net_income: int, total_bps: int}
     */
    #[Computed]
    public function allocation(): array
    {
        $net = $this->report['is']['net_income'];
        $partners = $this->partners;
        $totalBps = (int) $partners->sum('share_bps');

        $rows = [];
        $assigned = 0;
        $last = $partners->count() - 1;

        foreach ($partners->values() as $i => $partner) {
            $amount = $i === $last
                ? $net - $assigned
                : ($totalBps > 0 ? (int) round($net * $partner->share_bps / $totalBps) : 0);
            $assigned += $amount;

            $rows[] = ['name' => $partner->name, 'share_bps' => (int) $partner->share_bps, 'amount' => $amount];
        }

        return ['rows' => $rows, 'net_income' => $net, 'total_bps' => $totalBps];
    }

    public function addPartner(): void
    {
        $validated = $this->validate([
            'partnerName' => ['required', 'string', 'max:255'],
            'partnerShare' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        Partner::create([
            'company_id' => $this->company->id,
            'name' => $validated['partnerName'],
            'share_bps' => (int) round(((float) $validated['partnerShare']) * 100),
        ]);

        $this->reset('partnerName', 'partnerShare');
        unset($this->partners, $this->allocation);

        Flux::toast(variant: 'success', text: __('Partner added.'));
    }

    public function deletePartner(int $id): void
    {
        $partner = Partner::query()->where('company_id', $this->company->id)->findOrFail($id);
        $partner->delete();

        unset($this->partners, $this->allocation);

        Flux::toast(variant: 'success', text: __('Partner removed.'));
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private function exportRows(): array
    {
        $r = $this->report;
        $rows = [];

        foreach ($r['bs']['halves'] as $half) {
            foreach ($half['sections'] as $section) {
                foreach ($section['lines'] as $line) {
                    $rows[] = ['Balance Sheet', $line['code'], $line['label'], $line['amount']];
                }
            }
        }

        foreach ($r['is']['halves'] as $half) {
            foreach ($half['sections'] as $section) {
                foreach ($section['lines'] as $line) {
                    $rows[] = ['Income Statement', $line['code'], $line['label'], $line['amount']];
                }
            }
        }

        $rows[] = ['Income Statement', '', 'Net income', $r['is']['net_income']];

        foreach ($this->allocation['rows'] as $row) {
            $rows[] = ['Partner allocation', number_format($row['share_bps'] / 100, 2).'%', $row['name'], $row['amount']];
        }

        return $rows;
    }

    public function exportCsv()
    {
        return app(CsvExporter::class)->stream(
            "t5013-{$this->startDate}-{$this->endDate}.csv",
            ['Schedule', 'GIFI', 'Description', 'Amount'],
            collect($this->exportRows())->map(fn (array $r) => [$r[0], $r[1], $r[2], CsvExporter::cents($r[3])]),
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.t5013', [
            'company' => $this->company,
            'report' => $this->report,
            'allocation' => $this->allocation,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'title' => $this->effectiveTitle('T5013 Partnership'),
        ], "t5013-{$this->startDate}-{$this->endDate}.pdf");
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('T5013 Partnership'))"
        :subtitle="$company->name.' · '.__('T5013 — GIFI Schedule 100 / 125')"
        mode="range"
        :title-editable="true"
        :memorizable="true"
        :exports="['csv', 'pdf']"
    />

    @include('partials.reports.gifi-statement-readonly', [
        'report' => $this->report,
        'bsHeading' => __('Schedule 100 — Balance Sheet'),
        'isHeading' => __('Schedule 125 — Income Statement'),
    ])

    {{-- ───────────────── Schedule 50 — Partner income allocation ───────────────── --}}
    <div class="mb-8">
        <flux:heading size="lg" class="mb-1">{{ __('Schedule 50 — Partner allocation') }}</flux:heading>
        <flux:subheading class="mb-3">{{ __('Net income is allocated across partners by ownership share.') }}</flux:subheading>

        <div class="overflow-hidden rounded-lg border border-border">
            <table class="w-full text-sm" data-test="t5013-allocation">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('Partner') }}</th>
                        <th class="px-4 py-2 text-right font-medium text-muted-foreground">{{ __('Share %') }}</th>
                        <th class="px-4 py-2 text-right font-medium text-muted-foreground">{{ __('Allocated income') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->partners as $i => $partner)
                        @php($row = $this->allocation['rows'][$i] ?? ['amount' => 0])
                        <tr wire:key="partner-{{ $partner->id }}" data-test="t5013-partner-row">
                            <td class="px-4 py-2">{{ $partner->name }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($partner->share_bps / 100, 2) }}%</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($row['amount'] / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right">
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="deletePartner({{ $partner->id }})" wire:confirm="{{ __('Remove this partner?') }}" />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-muted-foreground">{{ __('No partners yet. Add partners to allocate income.') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-muted">
                    <tr class="font-semibold">
                        <td class="px-4 py-2">{{ __('Total') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($this->allocation['total_bps'] / 100, 2) }}%</td>
                        <td class="px-4 py-2 text-right font-mono" data-test="t5013-allocation-total">{{ number_format(collect($this->allocation['rows'])->sum('amount') / 100, 2) }}</td>
                        <td></td>
                    </tr>
                    @if ($this->allocation['total_bps'] !== 10000 && $this->partners->isNotEmpty())
                        <tr><td colspan="4" class="px-4 py-2 text-amber-600">{{ __('Partner shares do not add up to 100%.') }}</td></tr>
                    @endif
                </tfoot>
            </table>
        </div>

        <form wire:submit="addPartner" class="mt-3 flex flex-wrap items-end gap-3">
            <flux:input wire:model="partnerName" :label="__('Partner name')" class="max-w-xs" data-test="t5013-partner-name" />
            <flux:input wire:model="partnerShare" type="number" step="0.01" min="0" max="100" :label="__('Share %')" class="max-w-32" data-test="t5013-partner-share" />
            <flux:button type="submit" variant="primary" icon="plus" data-test="t5013-add-partner">{{ __('Add partner') }}</flux:button>
        </form>
    </div>
</section>
