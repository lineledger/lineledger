<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Invoice;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Unattributed AR')] class extends Component
{
    public Company $company;

    /** Free-text filter over the lines' memo / entry number. */
    #[Url(as: 'q')]
    public string $search = '';

    /** Search text for the target-customer picker. */
    public string $customerQuery = '';

    /** The customer the selected lines will be attributed to. */
    public ?int $assignToContactId = null;

    /** @var array<int, bool> journal-line id => selected */
    public array $selected = [];

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /** @return array<int, int> */
    private function arAccountIds(): array
    {
        return Account::query()
            ->where('company_id', $this->company->id)
            ->where('subtype', AccountSubtype::AccountsReceivable->value)
            ->pluck('id')
            ->all();
    }

    /**
     * Posted AR journal lines with no customer attribution — the rows that make up the
     * "(Unattributed AR)" total on the aging.
     *
     * @return Collection<int, object>
     */
    #[Computed]
    public function lines()
    {
        $accountIds = $this->arAccountIds();

        if ($accountIds === []) {
            return collect();
        }

        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $this->company->id)
            ->where('je.is_posted', true)
            ->whereNull('je.voided_at')
            ->whereIn('jl.account_id', $accountIds)
            ->whereNull('jl.contact_id');

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(fn ($w) => $w
                ->where('je.memo', 'like', $term)
                ->orWhere('jl.memo', 'like', $term)
                ->orWhere('je.entry_no', 'like', $term));
        }

        return $query
            ->orderBy('je.entry_date')
            ->orderBy('je.entry_no')
            ->limit(500)
            ->get([
                'jl.id', 'jl.debit_cents', 'jl.credit_cents', 'jl.memo as line_memo',
                'je.entry_no', 'je.entry_date', 'je.memo as entry_memo', 'je.source_type',
            ]);
    }

    /** Net unattributed AR across the whole company (debit − credit). */
    #[Computed]
    public function totalUnattributed(): int
    {
        $accountIds = $this->arAccountIds();

        if ($accountIds === []) {
            return 0;
        }

        return (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $this->company->id)
            ->where('je.is_posted', true)
            ->whereNull('je.voided_at')
            ->whereIn('jl.account_id', $accountIds)
            ->whereNull('jl.contact_id')
            ->sum(DB::raw('jl.debit_cents - jl.credit_cents'));
    }

    /** @return array<int, int> selected journal-line ids */
    private function selectedIds(): array
    {
        return array_map('intval', array_keys(array_filter($this->selected)));
    }

    #[Computed]
    public function selectedTotal(): int
    {
        $ids = $this->selectedIds();

        return $ids === []
            ? 0
            : (int) DB::table('journal_lines')->whereIn('id', $ids)->sum(DB::raw('debit_cents - credit_cents'));
    }

    /**
     * Existing customers matching the picker search.
     *
     * @return Collection<int, object>
     */
    #[Computed]
    public function customerOptions()
    {
        $query = Contact::query()
            ->where('company_id', $this->company->id)
            ->where('is_customer', true)
            ->where('is_active', true);

        if (trim($this->customerQuery) !== '') {
            $query->where('display_name', 'like', '%'.trim($this->customerQuery).'%');
        }

        return $query->orderBy('display_name')->limit(25)->get(['id', 'display_name']);
    }

    public function selectedCustomerName(): ?string
    {
        return $this->assignToContactId
            ? Contact::query()->where('company_id', $this->company->id)->where('id', $this->assignToContactId)->value('display_name')
            : null;
    }

    public function chooseCustomer(int $id): void
    {
        $this->assignToContactId = $id;
        $this->customerQuery = '';
    }

    public function clearCustomer(): void
    {
        $this->assignToContactId = null;
        $this->customerQuery = '';
    }

    /** Tick every line currently listed. */
    public function selectAll(): void
    {
        foreach ($this->lines as $line) {
            $this->selected[$line->id] = true;
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * Attribute the selected AR lines to the chosen customer. Only ever touches posted AR
     * control-account lines for this company that are currently unattributed — selection is
     * re-checked in SQL so a stale id can't reassign something else. GL amounts are untouched.
     */
    public function assign(): void
    {
        $this->validate([
            'assignToContactId' => ['required', 'integer', Rule::exists('contacts', 'id')->where('company_id', $this->company->id)->where('is_customer', true)],
        ], attributes: ['assignToContactId' => __('customer')]);

        $ids = $this->selectedIds();

        if ($ids === []) {
            Flux::toast(variant: 'warning', text: __('Select at least one line to attribute.'));

            return;
        }

        $updated = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $this->company->id)
            ->whereIn('jl.id', $ids)
            ->whereIn('jl.account_id', $this->arAccountIds())
            ->whereNull('jl.contact_id')
            ->update(['jl.contact_id' => $this->assignToContactId]);

        $name = $this->selectedCustomerName();

        $this->selected = [];
        $this->assignToContactId = null;
        unset($this->lines, $this->totalUnattributed, $this->selectedTotal);

        Flux::toast(variant: 'success', text: trans_choice('Attributed :count line to :name|Attributed :count lines to :name', $updated, ['count' => $updated, 'name' => $name]));
    }

    public function sourceLabel(?string $sourceType): string
    {
        return match ($sourceType) {
            Invoice::class => __('Invoice'),
            CustomerReceipt::class => __('Receipt'),
            CreditMemo::class => __('Credit Memo'),
            Cheque::class => __('Cheque'),
            Deposit::class => __('Deposit'),
            default => __('Journal'),
        };
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Unattributed AR') }}</flux:heading>
            <flux:subheading>
                {{ __('Accounts-receivable journal lines with no customer. Select lines and attribute them to the right customer.') }}
            </flux:subheading>
        </div>
        <div class="text-right">
            <div class="text-xs text-muted-foreground">{{ __('Total unattributed') }}</div>
            <div class="font-mono text-lg font-semibold" data-test="unattributed-total">{{ number_format($this->totalUnattributed / 100, 2) }}</div>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-2">
        <flux:button size="sm" :href="route('reports.ar-aging', ['company' => $company->slug])" icon="arrow-left" variant="ghost">
            {{ __('Back to AR Aging') }}
        </flux:button>

        <div class="grow"></div>

        <flux:input wire:model.live.debounce.250ms="search" :placeholder="__('Filter by memo or entry #…')" class="max-w-[260px]" data-test="unattributed-search" />
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-border p-3">
        <div class="min-w-[260px] grow">
            <flux:label>{{ __('Attribute selected to') }}</flux:label>
            <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" class="relative" wire:ignore.self>
                @if ($assignToContactId)
                    <div class="flex h-10 items-center justify-between rounded-md border border-border bg-card px-3">
                        <span class="truncate text-sm" data-test="assign-selected-customer">{{ $this->selectedCustomerName() }}</span>
                        <button type="button" wire:click="clearCustomer" class="text-muted-foreground hover:text-foreground" aria-label="{{ __('Clear') }}">
                            <flux:icon name="x-mark" class="size-4" />
                        </button>
                    </div>
                @else
                    <flux:input
                        wire:model.live.debounce.200ms="customerQuery"
                        :placeholder="__('Search customer…')"
                        @focus="open = true"
                        autocomplete="off"
                        data-test="assign-customer-search"
                    />
                    <div x-show="open" x-cloak x-transition.opacity.duration.100ms class="absolute left-0 right-0 top-full z-20 mt-1 max-h-60 overflow-auto rounded-md border border-border bg-card py-1 shadow-lg">
                        @forelse ($this->customerOptions as $opt)
                            <button type="button" wire:click="chooseCustomer({{ $opt->id }})" @click="open = false" class="block w-full px-3 py-2 text-left text-sm hover:bg-muted" data-test="assign-customer-option">
                                {{ $opt->display_name }}
                            </button>
                        @empty
                            <div class="px-3 py-2 text-sm text-muted-foreground">{{ trim($customerQuery) === '' ? __('Start typing to search.') : __('No matching customer.') }}</div>
                        @endforelse
                    </div>
                @endif
            </div>
            @error('assignToContactId') <flux:error :message="$message" /> @enderror
        </div>

        <flux:button variant="primary" wire:click="assign" icon="arrow-right-circle" data-test="assign-button">
            {{ __('Attribute :count selected', ['count' => count(array_filter($selected))]) }}
        </flux:button>

        <div class="text-right">
            <div class="text-xs text-muted-foreground">{{ __('Selected') }}</div>
            <div class="font-mono text-sm font-semibold" data-test="selected-total">{{ number_format($this->selectedTotal / 100, 2) }}</div>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="w-10 px-4 py-2 text-left">
                        <div class="flex gap-1">
                            <button type="button" wire:click="selectAll" class="text-xs text-blue-600 hover:underline dark:text-blue-400" data-test="select-all">{{ __('All') }}</button>
                            <span class="text-muted-foreground">/</span>
                            <button type="button" wire:click="clearSelection" class="text-xs text-blue-600 hover:underline dark:text-blue-400" data-test="clear-selection">{{ __('None') }}</button>
                        </div>
                    </th>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Entry') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Memo') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Debit') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Credit') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->lines as $line)
                    <tr data-test="unattributed-row">
                        <td class="px-4 py-2"><flux:checkbox wire:model.live="selected.{{ $line->id }}" data-test="line-check" /></td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($line->entry_date)->toDateString() }}</td>
                        <td class="px-4 py-2">{{ $this->sourceLabel($line->source_type) }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $line->entry_no }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ \Illuminate\Support\Str::limit($line->entry_memo ?? $line->line_memo ?? '', 60) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $line->debit_cents ? number_format($line->debit_cents / 100, 2) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $line->credit_cents ? number_format($line->credit_cents / 100, 2) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ __('No unattributed AR lines.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->lines->count() === 500)
        <p class="mt-2 text-xs text-muted-foreground">{{ __('Showing the first 500 lines. Use the filter to narrow them down.') }}</p>
    @endif
</section>
