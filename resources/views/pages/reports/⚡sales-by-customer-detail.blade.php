<?php

use App\Concerns\HasReportDateRange;
use App\Enums\CreditMemoStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SalesReceiptStatus;
use App\Models\Company;
use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\SalesReceipt;
use App\Services\Reporting\CsvExporter;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales by Customer (Detail)')] class extends Component {
    use HasReportDateRange;

    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportDateRange();
    }

    /**
     * Every sales document (invoice, pay-now sales receipt, less credit memos)
     * in the period, as one row per document. Pre-tax subtotals, matching the
     * Sales by Customer summary.
     *
     * @return Collection<int, array{contact_id: ?int, contact: string, date: string, type: string, no: string, amount_cents: int}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $rows = collect();

        Invoice::query()->with('contact:id,display_name')
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->whereBetween('invoice_date', [$this->startDate, $this->endDate])
            ->get()
            ->each(fn ($i) => $rows->push([
                'contact_id' => $i->contact_id,
                'contact' => $i->contact?->display_name ?? __('No contact'),
                'date' => $i->invoice_date->toDateString(),
                'type' => __('Invoice'),
                'no' => (string) $i->invoice_no,
                'amount_cents' => (int) $i->subtotal_cents,
            ]));

        SalesReceipt::query()->with('contact:id,display_name')
            ->where('status', SalesReceiptStatus::Posted->value)
            ->whereBetween('receipt_date', [$this->startDate, $this->endDate])
            ->get()
            ->each(fn ($r) => $rows->push([
                'contact_id' => $r->contact_id,
                'contact' => $r->contact?->display_name ?? __('Cash sale'),
                'date' => $r->receipt_date->toDateString(),
                'type' => __('Sales receipt'),
                'no' => (string) $r->sales_receipt_no,
                'amount_cents' => (int) $r->subtotal_cents,
            ]));

        CreditMemo::query()->with('contact:id,display_name')
            ->whereNotIn('status', [CreditMemoStatus::Draft->value, CreditMemoStatus::Void->value])
            ->whereBetween('credit_memo_date', [$this->startDate, $this->endDate])
            ->get()
            ->each(fn ($c) => $rows->push([
                'contact_id' => $c->contact_id,
                'contact' => $c->contact?->display_name ?? __('No contact'),
                'date' => $c->credit_memo_date->toDateString(),
                'type' => __('Credit memo'),
                'no' => (string) $c->credit_memo_no,
                'amount_cents' => -(int) $c->subtotal_cents,
            ]));

        return $rows->sortBy([['contact', 'asc'], ['date', 'asc']])->values();
    }

    /**
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    #[Computed]
    public function grouped(): Collection
    {
        return $this->rows->groupBy('contact');
    }

    public function exportCsv()
    {
        $rows = $this->rows->map(fn (array $r): array => [
            $r['contact'], $r['date'], $r['type'], $r['no'], CsvExporter::cents($r['amount_cents']),
        ]);
        $rows->push(['Total', '', '', '', CsvExporter::cents((int) $this->rows->sum('amount_cents'))]);

        return app(CsvExporter::class)->stream(
            "sales-by-customer-detail-{$this->startDate}-{$this->endDate}.csv",
            ['Customer', 'Date', 'Type', 'Document', 'Amount'],
            $rows,
        );
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="__('Sales by Customer (Detail)')"
        :subtitle="$company->name.' · '.$startDate.' '.__('to').' '.$endDate"
        mode="range"
        :exports="['csv']"
        :exports-disabled="$this->rows->isEmpty()"
    />

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Document') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->grouped as $contact => $docs)
                    <tr class="bg-muted/50">
                        <td colspan="4" class="px-4 py-2 font-semibold">{{ $contact }}</td>
                    </tr>
                    @foreach ($docs as $doc)
                        <tr data-test="sales-detail-row">
                            <td class="px-4 py-2 whitespace-nowrap">{{ $doc['date'] }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ $doc['type'] }}</td>
                            <td class="px-4 py-2 font-mono">{{ $doc['no'] }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($doc['amount_cents'] / 100, 2) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td colspan="3" class="px-4 py-2 text-right font-medium">{{ __('Subtotal for :name', ['name' => $contact]) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-medium" data-test="sales-detail-subtotal">{{ number_format($docs->sum('amount_cents') / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-muted-foreground">{{ __('No sales in this period.') }}</td></tr>
                @endforelse
            </tbody>
            @if ($this->rows->isNotEmpty())
                <tfoot class="bg-muted">
                    <tr>
                        <td colspan="3" class="px-4 py-3 font-semibold">{{ __('Total') }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold" data-test="sales-detail-total">{{ number_format($this->rows->sum('amount_cents') / 100, 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</section>
