<?php

use App\Livewire\Concerns\ImportsCsvList;
use App\Livewire\Concerns\InteractsWithOpeningBalances;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\OpeningBalances\CustomerOpeningBalanceSync;
use App\Services\OpeningBalances\Importers\OpeningCustomerBalancesCsvImporter;
use App\Services\OpeningBalances\OpeningBalanceStatusBuilder;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Opening customer balances')] class extends Component {
    use ImportsCsvList {
        runImport as baseRunImport;
    }
    use InteractsWithOpeningBalances;
    use WithFileUploads;

    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    /** @var array<int|string, string> Balance inputs keyed by contact id (contact currency, signed). */
    public array $bal = [];

    public function mount(Company $company): void
    {
        abort_unless(auth()->user()?->ownsCompany($company), 403);

        $this->company = $company;
        $this->resolveObState();
    }

    public function updatedSearch(): void
    {
        $this->bal = [];
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function customers(): Collection
    {
        $customers = Contact::query()
            ->where('is_customer', true)
            ->when($this->search !== '', fn ($q) => $q->where('display_name', 'like', '%'.$this->search.'%'))
            ->orderBy('display_name')
            ->get();

        $invoices = Invoice::query()
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get()
            ->groupBy('contact_id');

        $memos = CreditMemo::query()
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get()
            ->groupBy('contact_id');

        return $customers->map(function (Contact $contact) use ($invoices, $memos) {
            $docs = collect($invoices->get($contact->id, collect()))
                ->map(fn ($i) => ['label' => $i->invoice_no, 'kind' => 'invoice'])
                ->merge(collect($memos->get($contact->id, collect()))->map(fn ($m) => ['label' => $m->credit_memo_no, 'kind' => 'credit memo']));

            $net = (int) collect($invoices->get($contact->id, collect()))->sum('total_cents')
                - (int) collect($memos->get($contact->id, collect()))->sum('total_cents');

            $this->bal[$contact->id] ??= $net === 0 ? '' : Money::fromCents($net)->toDecimalString();

            return [
                'contact' => $contact,
                'net' => $net,
                'docs' => $docs,
            ];
        });
    }

    #[Computed]
    public function footer(): array
    {
        return app(OpeningBalanceStatusBuilder::class)->build($this->obState->refresh())['ar'];
    }

    public function updatedBal($value, $key): void
    {
        if (! $this->obEditable()) {
            $this->bal = [];

            return;
        }

        $contact = Contact::query()->where('is_customer', true)->findOrFail((int) $key);

        $money = (string) $value === '' ? Money::zero() : Money::tryFromString((string) $value);

        if ($money === null) {
            Flux::toast(variant: 'danger', text: __('Enter a valid amount — use a minus sign for a customer credit.'));
            $this->bal = [];
            unset($this->customers);

            return;
        }

        try {
            app(CustomerOpeningBalanceSync::class)->set($this->obState, $contact, $money->cents);
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        $this->bal = [];
        unset($this->customers, $this->footer);
    }

    public function runImport(): void
    {
        $this->baseRunImport();
        $this->bal = [];
        unset($this->customers, $this->footer);
    }

    /** Updates count too — a re-import of corrected figures must stay importable. */
    #[Computed]
    public function importCreatableCount(): int
    {
        return collect($this->importPreviewRows ?? [])
            ->whereIn('action', ['create', 'update'])
            ->count();
    }

    protected function csvImporter(): CompanyCsvImporter
    {
        return app(OpeningCustomerBalancesCsvImporter::class);
    }

    protected function csvImportModalName(): string
    {
        return 'ob-ar-import';
    }

    protected function csvImportLabelPlural(): string
    {
        return __('customer balances');
    }

    public function money(int $cents, ?string $currency = null): string
    {
        return Money::fromCents($cents, $currency ?? $this->company->currency_code ?? 'CAD')->format();
    }
}; ?>

<section class="w-full">
    @include('partials.opening-balances-heading')

    <div class="mb-4 flex items-center justify-between gap-3">
        <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" :placeholder="__('Search customers…')" class="max-w-72" />
        <flux:button icon="arrow-up-tray" wire:click="openImport" :disabled="$obState->isFinalized()" data-test="ob-ar-import-button">{{ __('Import') }}</flux:button>
    </div>

    <flux:card>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr>
                        <th class="py-2 pr-3 font-medium">{{ __('Customer') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('Opening documents') }}</th>
                        <th class="w-44 py-2 text-right font-medium">{{ __('Opening balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->customers as $row)
                        <tr class="border-t border-zinc-100 dark:border-zinc-800" wire:key="ob-ar-{{ $row['contact']->id }}" data-test="ob-ar-row">
                            <td class="py-1.5 pr-3">
                                {{ $row['contact']->qualifiedName() }}
                                @if ($row['contact']->currency_code && ! $company->isHomeCurrency($row['contact']->currency_code))
                                    <flux:badge size="sm" class="ms-1">{{ strtoupper($row['contact']->currency_code) }}</flux:badge>
                                @endif
                            </td>
                            <td class="py-1.5 pr-3 text-muted-foreground">
                                {{ $row['docs']->map(fn ($d) => $d['label'])->join(', ') ?: '—' }}
                                @if ($row['docs']->count() > 1)
                                    <flux:badge size="sm" color="amber" class="ms-1">{{ __('saving consolidates') }}</flux:badge>
                                @endif
                            </td>
                            <td class="py-1.5">
                                <x-amount-input model="bal.{{ $row['contact']->id }}" modifiers=".blur" class="text-right" :disabled="$obState->isFinalized()" data-test="ob-ar-balance-{{ $row['contact']->id }}" />
                            </td>
                        </tr>
                    @endforeach

                    @if ($this->customers->isEmpty())
                        <tr>
                            <td colspan="3" class="py-8 text-center text-muted-foreground">
                                {{ __('No customers found. Create customers first, then enter their opening balances here.') }}
                            </td>
                        </tr>
                    @endif
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-zinc-200 font-medium dark:border-zinc-700">
                        <td class="py-2 pr-3">{{ __('Entered (home currency)') }}</td>
                        <td></td>
                        <td class="py-2 text-right tabular-nums" data-test="ob-ar-detail-total">{{ $this->money($this->footer['detail']) }}</td>
                    </tr>
                    <tr class="text-muted-foreground">
                        <td class="py-1 pr-3">{{ __('Draft trial balance target') }}</td>
                        <td></td>
                        <td class="py-1 text-right tabular-nums" data-test="ob-ar-target">{{ $this->money($this->footer['target']) }}</td>
                    </tr>
                    @php($variance = $this->footer['target'] - $this->footer['gl'])
                    <tr class="{{ $variance === 0 ? 'text-emerald-600' : 'text-amber-600' }} font-medium">
                        <td class="py-1 pr-3">{{ __('Still to explain') }}</td>
                        <td></td>
                        <td class="py-1 text-right tabular-nums" data-test="ob-ar-variance">{{ $this->money($variance) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </flux:card>

    <x-csv-import-modal
        name="ob-ar-import"
        :template-url="route('opening-balances.template', ['company' => $company->slug, 'step' => 'customer_balances'])"
        :subtitle="__('Net opening balance per customer — use a minus sign for a customer credit. Re-importing corrected figures updates the same opening documents.')"
        :preview-rows="$importPreviewRows"
        :row-errors="$importErrors"
        :summary="$importSummary"
        :creatable-count="$this->importCreatableCount"
        :has-file="(bool) $importFile"
    />
</section>
