<?php

use App\Livewire\Concerns\ImportsCsvList;
use App\Livewire\Concerns\InteractsWithOpeningBalances;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\VendorCredit;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\OpeningBalances\Importers\OpeningVendorBalancesCsvImporter;
use App\Services\OpeningBalances\OpeningBalanceStatusBuilder;
use App\Services\OpeningBalances\VendorOpeningBalanceSync;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Opening vendor balances')] class extends Component {
    use ImportsCsvList {
        runImport as baseRunImport;
    }
    use InteractsWithOpeningBalances;
    use WithFileUploads;

    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    /** @var array<int|string, string> Balance inputs keyed by contact id (vendor currency; positive = you owe). */
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
    public function vendors(): Collection
    {
        $vendors = Contact::query()
            ->where('is_vendor', true)
            ->when($this->search !== '', fn ($q) => $q->where('display_name', 'like', '%'.$this->search.'%'))
            ->orderBy('display_name')
            ->get();

        $bills = Bill::query()
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get()
            ->groupBy('contact_id');

        $credits = VendorCredit::query()
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get()
            ->groupBy('contact_id');

        return $vendors->map(function (Contact $contact) use ($bills, $credits) {
            $docs = collect($bills->get($contact->id, collect()))
                ->map(fn ($b) => ['label' => $b->bill_no, 'kind' => 'bill'])
                ->merge(collect($credits->get($contact->id, collect()))->map(fn ($c) => ['label' => $c->vendor_credit_no, 'kind' => 'vendor credit']));

            $net = (int) collect($bills->get($contact->id, collect()))->sum('total_cents')
                - (int) collect($credits->get($contact->id, collect()))->sum('total_cents');

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
        return app(OpeningBalanceStatusBuilder::class)->build($this->obState->refresh())['ap'];
    }

    public function updatedBal($value, $key): void
    {
        if (! $this->obEditable()) {
            $this->bal = [];

            return;
        }

        $contact = Contact::query()->where('is_vendor', true)->findOrFail((int) $key);

        $money = (string) $value === '' ? Money::zero() : Money::tryFromString((string) $value);

        if ($money === null) {
            Flux::toast(variant: 'danger', text: __('Enter a valid amount — use a minus sign for a vendor credit.'));
            $this->bal = [];
            unset($this->vendors);

            return;
        }

        try {
            app(VendorOpeningBalanceSync::class)->set($this->obState, $contact, $money->cents);
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        $this->bal = [];
        unset($this->vendors, $this->footer);
    }

    public function runImport(): void
    {
        $this->baseRunImport();
        $this->bal = [];
        unset($this->vendors, $this->footer);
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
        return app(OpeningVendorBalancesCsvImporter::class);
    }

    protected function csvImportModalName(): string
    {
        return 'ob-ap-import';
    }

    protected function csvImportLabelPlural(): string
    {
        return __('vendor balances');
    }

    public function money(int $cents, ?string $currency = null): string
    {
        return Money::fromCents($cents, $currency ?? $this->company->currency_code ?? 'CAD')->format();
    }
}; ?>

<section class="w-full">
    @include('partials.opening-balances-heading')

    <div class="mb-4 flex items-center justify-between gap-3">
        <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" :placeholder="__('Search vendors…')" class="max-w-72" />
        <flux:button icon="arrow-up-tray" wire:click="openImport" :disabled="$obState->isFinalized()" data-test="ob-ap-import-button">{{ __('Import') }}</flux:button>
    </div>

    <flux:card>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr>
                        <th class="py-2 pr-3 font-medium">{{ __('Vendor') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('Opening documents') }}</th>
                        <th class="w-44 py-2 text-right font-medium">{{ __('Opening balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->vendors as $row)
                        <tr class="border-t border-zinc-100 dark:border-zinc-800" wire:key="ob-ap-{{ $row['contact']->id }}" data-test="ob-ap-row">
                            <td class="py-1.5 pr-3">
                                {{ $row['contact']->display_name }}
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
                                <x-amount-input model="bal.{{ $row['contact']->id }}" modifiers=".live.blur" placeholder="0.00" class="text-right" :disabled="$obState->isFinalized()" data-test="ob-ap-balance-{{ $row['contact']->id }}" />
                            </td>
                        </tr>
                    @endforeach

                    @if ($this->vendors->isEmpty())
                        <tr>
                            <td colspan="3" class="py-8 text-center text-muted-foreground">
                                {{ __('No vendors found. Create vendors first, then enter their opening balances here.') }}
                            </td>
                        </tr>
                    @endif
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-zinc-200 font-medium dark:border-zinc-700">
                        <td class="py-2 pr-3">{{ __('Entered (home currency)') }}</td>
                        <td></td>
                        <td class="py-2 text-right tabular-nums" data-test="ob-ap-detail-total">{{ $this->money($this->footer['detail']) }}</td>
                    </tr>
                    <tr class="text-muted-foreground">
                        <td class="py-1 pr-3">{{ __('Draft trial balance target') }}</td>
                        <td></td>
                        <td class="py-1 text-right tabular-nums" data-test="ob-ap-target">{{ $this->money(-$this->footer['target']) }}</td>
                    </tr>
                    @php($variance = (-$this->footer['target']) - (-$this->footer['gl']))
                    <tr class="{{ $variance === 0 ? 'text-emerald-600' : 'text-amber-600' }} font-medium">
                        <td class="py-1 pr-3">{{ __('Still to explain') }}</td>
                        <td></td>
                        <td class="py-1 text-right tabular-nums" data-test="ob-ap-variance">{{ $this->money($variance) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </flux:card>

    <x-csv-import-modal
        name="ob-ap-import"
        :template-url="route('opening-balances.template', ['company' => $company->slug, 'step' => 'vendor_balances'])"
        :subtitle="__('Net opening balance per vendor — use a minus sign for a vendor credit. Re-importing corrected figures updates the same opening documents.')"
        :preview-rows="$importPreviewRows"
        :row-errors="$importErrors"
        :summary="$importSummary"
        :creatable-count="$this->importCreatableCount"
        :has-file="(bool) $importFile"
    />
</section>
