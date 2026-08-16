<?php

use App\Concerns\EmailsReport;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\Memorizable;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Customer Contact List')] class extends Component {
    use EmailsReport;
    use HasCustomReportHeader;
    use Memorizable;

    private const SORT_FIELDS = ['name', 'open_balance'];

    public Company $company;

    #[Url(as: 'inactive')]
    public bool $includeInactive = false;

    #[Url(as: 'sort')]
    public string $sortField = 'name';

    #[Url(as: 'dir')]
    public string $sortDir = 'asc';

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->applyMemorized((int) request('memorized'));
    }

    protected function reportKey(): string
    {
        return 'reports.customer-contact-list';
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
     * @return array<int, array{id: int, name: string, company: string, email: string, phone: string, address: string, terms: string, currency: string, balance: int}>
     */
    #[Computed]
    public function rows(): array
    {
        $orderColumn = $this->sortField === 'open_balance' ? 'ar_balance_cents' : 'display_name';
        $dir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        return Contact::query()
            ->where('is_customer', true)
            ->when(! $this->includeInactive, fn ($q) => $q->where('is_active', true))
            ->with('defaultTerms')
            ->orderBy($orderColumn, $dir)
            ->orderBy('display_name')
            ->get()
            ->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'name' => $contact->display_name,
                'company' => $contact->company_name ?? '',
                'email' => $contact->email ?? '',
                'phone' => $contact->phone ?: ($contact->mobile ?? ''),
                'address' => implode(', ', array_filter([
                    $contact->billing_line1,
                    $contact->billing_line2,
                    $contact->billing_city,
                    $contact->billing_region,
                    $contact->billing_postal_code,
                    $contact->billing_country,
                ])),
                'terms' => $contact->defaultTerms?->name ?? '',
                'currency' => $contact->currency_code ?? $this->company->currency_code,
                'balance' => (int) $contact->ar_balance_cents,
            ])
            ->all();
    }

    private function totalBalance(): int
    {
        return array_sum(array_column($this->rows, 'balance'));
    }

    public function exportCsv()
    {
        return app(CsvExporter::class)->stream(
            'customer-contact-list.csv',
            ['Name', 'Company', 'Email', 'Phone', 'Billing address', 'Terms', 'Currency', 'Open balance'],
            collect($this->rows)->map(fn (array $row) => [
                $row['name'], $row['company'], $row['email'], $row['phone'],
                $row['address'], $row['terms'], $row['currency'],
                CsvExporter::cents($row['balance']),
            ])->push(['TOTAL', '', '', '', '', '', '', CsvExporter::cents($this->totalBalance())]),
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->listTable(
            'customer-contact-list.xlsx',
            'Customer Contacts',
            $this->effectiveTitle('Customer Contact List'),
            $this->company,
            [],
            ['Name', 'Company', 'Email', 'Phone', 'Billing address', 'Terms', 'Currency', 'Open balance'],
            collect($this->rows)->map(fn (array $row) => [
                $row['name'], $row['company'], $row['email'], $row['phone'],
                $row['address'], $row['terms'], $row['currency'], $row['balance'],
            ])->all(),
            moneyColumns: [8],
            columnWidths: [1 => 28, 2 => 24, 3 => 28, 4 => 16, 5 => 44, 6 => 14, 7 => 10, 8 => 16],
            totals: ['Total', '', '', '', '', '', '', $this->totalBalance()],
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.list-table', [
            'company' => $this->company,
            'title' => $this->effectiveTitle('Customer Contact List'),
            'period' => null,
            'headers' => [
                ['label' => 'Name'], ['label' => 'Company'], ['label' => 'Email'], ['label' => 'Phone'],
                ['label' => 'Billing address'], ['label' => 'Terms'], ['label' => 'Currency'],
                ['label' => 'Open balance', 'num' => true],
            ],
            'rows' => collect($this->rows)->map(fn (array $row) => [
                ['value' => $row['name']],
                ['value' => $row['company']],
                ['value' => $row['email']],
                ['value' => $row['phone']],
                ['value' => $row['address']],
                ['value' => $row['terms']],
                ['value' => $row['currency']],
                ['value' => number_format($row['balance'] / 100, 2), 'num' => true],
            ])->all(),
            'totals' => [
                ['value' => 'Total'], ['value' => ''], ['value' => ''], ['value' => ''],
                ['value' => ''], ['value' => ''], ['value' => ''],
                ['value' => number_format($this->totalBalance() / 100, 2), 'num' => true],
            ],
            'emptyMessage' => 'No customers to list.',
        ], 'customer-contact-list.pdf');
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Customer Contact List'))"
        :subtitle="__('Customer names, contact details, terms, and open balances.')"
        mode="none"
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
                    <th class="px-4 py-2 text-left"><x-sort-header field="name" :current-field="$sortField" :current-dir="$sortDir" :label="__('Name')" /></th>
                    <th class="px-4 py-2 text-left">{{ __('Company') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Email') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Phone') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Billing address') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Terms') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Currency') }}</th>
                    <th class="px-4 py-2 text-right"><x-sort-header field="open_balance" :current-field="$sortField" :current-dir="$sortDir" :label="__('Open balance')" align="right" /></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $row)
                    <tr data-test="contact-list-row">
                        <td class="px-4 py-2">
                            <a
                                href="{{ route('reports.contact-statement', ['company' => $company->slug, 'contact' => $row['id'], 'kind' => 'ar']) }}"
                                class="underline"
                                data-test="contact-statement-link"
                            >{{ $row['name'] }}</a>
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['company'] }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['email'] }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['phone'] }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['address'] }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['terms'] }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['currency'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($row['balance'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-muted-foreground">{{ __('No customers to list.') }}</td></tr>
                @endforelse
            </tbody>
            @if (! empty($this->rows))
                <tfoot class="bg-muted">
                    <tr>
                        <td colspan="7" class="px-4 py-2 text-right font-semibold">{{ __('Total') }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold" data-test="contact-list-total">{{ number_format(array_sum(array_column($this->rows, 'balance')) / 100, 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</section>
