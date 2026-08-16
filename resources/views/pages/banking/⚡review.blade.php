<?php

use App\Actions\Banking\AddStatementLineEntry;
use App\Actions\Banking\BulkCategorizeStatementLines;
use App\Actions\Banking\BulkSetStatementLineStatus;
use App\Actions\Banking\PairStatementTransfer;
use App\Actions\Banking\SplitStatementLine;
use App\Services\Banking\TransferPairMatcher;
use App\Enums\AccountSubtype;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Contact;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('For Review')] class extends Component {
    public Company $company;

    public ?int $accountFilter = null;

    public bool $showExcluded = false;

    /** @var array<int, int|string> selected line ids for bulk actions */
    public array $selected = [];

    public ?int $bulkCategory = null;

    /** @var array<int, int|null> lineId => chosen category account id */
    public array $categories = [];

    /** @var array<int, int|null> lineId => chosen contact id */
    public array $contacts = [];

    // Split modal.
    public ?int $splittingLineId = null;

    public int $splitTargetCents = 0;

    /** @var array<int, array{account_id: ?int, amount: string}> */
    public array $splits = [];

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * @return Collection<int, BankStatementLine>
     */
    #[Computed]
    public function rows(): Collection
    {
        $base = $this->showExcluded
            ? BankStatementLine::query()
                ->where('match_status', StatementLineMatchStatus::Ignored->value)
                ->whereNull('created_journal_entry_id')
            : BankStatementLine::query()->forReview();

        return $base
            ->when($this->accountFilter, fn ($q) => $q->where('account_id', $this->accountFilter))
            ->with('account:id,code,name')
            ->orderBy('txn_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Suggested inter-account transfer pairs hiding in the feed.
     *
     * @return Collection<int, array{out: BankStatementLine, in: BankStatementLine, days: int}>
     */
    #[Computed]
    public function transferCandidates(): Collection
    {
        if ($this->showExcluded) {
            return collect();
        }

        return app(TransferPairMatcher::class)->candidates($this->company);
    }

    public function recordTransfer(int $outId, int $inId, PairStatementTransfer $action): void
    {
        $out = $this->lineForReview($outId);
        $in = $this->lineForReview($inId);

        if ($out === null || $in === null) {
            return;
        }

        try {
            $action->handle($out, $in);
            unset($this->rows, $this->transferCandidates);
            Flux::toast(variant: 'success', text: __('Transfer recorded.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function updatedShowExcluded(): void
    {
        $this->selected = [];
    }

    public function selectAllVisible(): void
    {
        $this->selected = $this->rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function bulkCategorize(BulkCategorizeStatementLines $action): void
    {
        if (! $this->bulkCategory || $this->selected === []) {
            return;
        }

        try {
            $count = $action->handle($this->selected, (int) $this->bulkCategory);
            $this->selected = [];
            $this->bulkCategory = null;
            unset($this->rows);
            Flux::toast(variant: 'success', text: __(':count added to your books.', ['count' => $count]));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function bulkExclude(BulkSetStatementLineStatus $action): void
    {
        $action->exclude($this->selected);
        $this->selected = [];
        unset($this->rows);
    }

    public function bulkInclude(BulkSetStatementLineStatus $action): void
    {
        $action->include($this->selected);
        $this->selected = [];
        unset($this->rows);
    }

    public function includeLine(int $lineId, BulkSetStatementLineStatus $action): void
    {
        $action->include([$lineId]);
        unset($this->rows);
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function bankAccounts(): Collection
    {
        return Account::query()
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return Account::query()
            ->selectableForItemAccount()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->name}"])
            ->all();
    }

    /**
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function contactOptions(): Collection
    {
        return Contact::query()->where('is_active', true)->orderBy('display_name')->get(['id', 'display_name']);
    }

    public function accept(int $lineId, AddStatementLineEntry $action): void
    {
        $line = $this->lineForReview($lineId);

        if ($line === null) {
            return;
        }

        $accountId = $this->categories[$lineId] ?? $line->suggested_account_id;

        if (! $accountId) {
            Flux::toast(variant: 'danger', text: __('Choose a category first.'));

            return;
        }

        try {
            $action->handle($line, (int) $accountId, $this->contacts[$lineId] ?? null);
            unset($this->rows);
            Flux::toast(variant: 'success', text: __('Added to your books.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function exclude(int $lineId): void
    {
        BankStatementLine::query()
            ->forReview()
            ->whereKey($lineId)
            ->update(['match_status' => StatementLineMatchStatus::Ignored->value]);

        unset($this->rows);
    }

    public function openSplit(int $lineId): void
    {
        $line = $this->lineForReview($lineId);

        if ($line === null) {
            return;
        }

        $this->splittingLineId = $lineId;
        $this->splitTargetCents = abs((int) $line->amount_cents);
        $this->splits = [['account_id' => null, 'amount' => number_format($this->splitTargetCents / 100, 2, '.', '')]];

        Flux::modal('split-line')->show();
    }

    public function addSplit(): void
    {
        $this->splits[] = ['account_id' => null, 'amount' => ''];
    }

    public function removeSplit(int $index): void
    {
        unset($this->splits[$index]);
        $this->splits = array_values($this->splits);
    }

    public function saveSplit(SplitStatementLine $action): void
    {
        $line = $this->splittingLineId ? $this->lineForReview($this->splittingLineId) : null;

        if ($line === null) {
            $this->cancelSplit();

            return;
        }

        $payload = collect($this->splits)
            ->filter(fn (array $s): bool => $s['account_id'] && trim((string) $s['amount']) !== '')
            ->map(fn (array $s): array => [
                'account_id' => (int) $s['account_id'],
                'amount_cents' => Money::fromString((string) $s['amount'])->cents,
            ])
            ->values()
            ->all();

        try {
            $action->handle($line, $payload);
            $this->cancelSplit();
            unset($this->rows);
            Flux::toast(variant: 'success', text: __('Split added to your books.'));
        } catch (PostingValidationException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function cancelSplit(): void
    {
        $this->splittingLineId = null;
        $this->splits = [];
        $this->splitTargetCents = 0;
        Flux::modal('split-line')->close();
    }

    private function lineForReview(int $lineId): ?BankStatementLine
    {
        return BankStatementLine::query()->forReview()->whereKey($lineId)->first();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('For Review') }}</flux:heading>
            <flux:subheading>{{ __('Imported bank transactions waiting to be categorized, across every account.') }}</flux:subheading>
        </div>
        <div class="flex items-end gap-3">
            <flux:select wire:model.live="accountFilter" :label="__('Account')" class="max-w-[240px]" data-test="review-account-filter">
                <flux:select.option value="">{{ __('All accounts') }}</flux:select.option>
                @foreach ($this->bankAccounts as $acct)
                    <flux:select.option :value="$acct->id">{{ $acct->code }} — {{ $acct->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:switch wire:model.live="showExcluded" :label="__('Excluded')" data-test="review-show-excluded" />
        </div>
    </div>

    {{-- Suggested transfers --}}
    @if ($this->transferCandidates->isNotEmpty())
        <div class="mb-4 overflow-hidden rounded-lg border border-border" data-test="transfer-suggestions">
            <div class="border-b border-border bg-muted px-4 py-2 text-sm font-medium">{{ __('Suggested transfers') }}</div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-border">
                    @foreach ($this->transferCandidates as $pair)
                        <tr data-test="transfer-pair" wire:key="xfer-{{ $pair['out']->id }}-{{ $pair['in']->id }}">
                            <td class="px-4 py-2">
                                {{ $pair['out']->account?->name }} → {{ $pair['in']->account?->name }}
                                <span class="text-muted-foreground">· {{ $pair['out']->txn_date?->toDateString() }}</span>
                            </td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format(abs($pair['out']->amount_cents) / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right">
                                <flux:button size="xs" variant="primary" wire:click="recordTransfer({{ $pair['out']->id }}, {{ $pair['in']->id }})" data-test="record-transfer">{{ __('Record transfer') }}</flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Bulk action bar --}}
    @if (count($selected) > 0)
        <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted px-4 py-2" data-test="review-bulk-bar">
            <span class="text-sm font-medium">{{ __(':n selected', ['n' => count($selected)]) }}</span>
            <flux:button size="xs" variant="ghost" wire:click="clearSelection">{{ __('Clear') }}</flux:button>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                @if ($showExcluded)
                    <flux:button size="sm" variant="primary" wire:click="bulkInclude" data-test="bulk-include">{{ __('Include') }}</flux:button>
                @else
                    <flux:select wire:model="bulkCategory" size="sm" class="min-w-[200px]" data-test="bulk-category">
                        <flux:select.option value="">{{ __('Categorize as…') }}</flux:select.option>
                        @foreach ($this->categoryOptions as $opt)
                            <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button size="sm" variant="primary" wire:click="bulkCategorize" :disabled="! $bulkCategory" data-test="bulk-categorize">{{ __('Categorize') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="bulkExclude" data-test="bulk-exclude">{{ __('Exclude') }}</flux:button>
                @endif
            </div>
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-3 py-2 text-left"><flux:checkbox wire:click="selectAllVisible" :checked="count($selected) > 0 && count($selected) === $this->rows->count()" data-test="select-all" /></th>
                    <th class="px-3 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Amount') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Category') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Contact') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $line)
                    <tr data-test="review-row" wire:key="line-{{ $line->id }}">
                        <td class="px-3 py-2"><flux:checkbox wire:model.live="selected" value="{{ $line->id }}" data-test="review-select" /></td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $line->txn_date?->toDateString() }}</td>
                        <td class="px-3 py-2 text-muted-foreground">{{ $line->account?->code }}</td>
                        <td class="px-3 py-2">{{ $line->description }}</td>
                        <td class="px-3 py-2 text-right font-mono {{ $line->amount_cents < 0 ? 'text-red-600 dark:text-red-500' : 'text-green-600 dark:text-green-500' }}">
                            {{ number_format($line->amount_cents / 100, 2) }}
                        </td>
                        <td class="px-3 py-2">
                            <flux:select wire:model="categories.{{ $line->id }}" size="sm" class="min-w-[180px]" data-test="review-category">
                                <flux:select.option value="">{{ $line->suggested_account_id ? ($line->suggestedAccount?->name ?? __('— pick —')) : __('— pick —') }}</flux:select.option>
                                @foreach ($this->categoryOptions as $opt)
                                    <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </td>
                        <td class="px-3 py-2">
                            <flux:select wire:model="contacts.{{ $line->id }}" size="sm" class="min-w-[140px]" data-test="review-contact">
                                <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                                @foreach ($this->contactOptions as $contact)
                                    <flux:select.option :value="$contact->id">{{ $contact->display_name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex justify-end gap-1">
                                @if ($showExcluded)
                                    <flux:button size="xs" variant="ghost" wire:click="includeLine({{ $line->id }})" data-test="row-include">{{ __('Include') }}</flux:button>
                                @else
                                    <flux:button size="xs" variant="primary" wire:click="accept({{ $line->id }})" data-test="review-accept">{{ __('Accept') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="openSplit({{ $line->id }})" data-test="review-split">{{ __('Split') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="exclude({{ $line->id }})" data-test="review-exclude">{{ __('Exclude') }}</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-8 text-center text-muted-foreground">{{ $showExcluded ? __('No excluded transactions.') : __('Nothing to review — every imported transaction is categorized.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Split modal --}}
    <flux:modal name="split-line" class="md:w-[640px]" wire:close="cancelSplit">
        @php($splitSum = collect($splits)->sum(fn ($s) => trim((string) $s['amount']) === '' ? 0 : Money::fromString((string) $s['amount'])->cents))
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Split transaction') }}</flux:heading>
            <flux:subheading>{{ __('Divide this transaction across categories. The parts must add up to the total.') }}</flux:subheading>

            @foreach ($splits as $i => $split)
                <div class="grid grid-cols-12 gap-2" wire:key="split-{{ $i }}" data-test="split-row">
                    <flux:select wire:model="splits.{{ $i }}.account_id" class="col-span-7" data-test="split-account">
                        <flux:select.option value="">{{ __('— category —') }}</flux:select.option>
                        @foreach ($this->categoryOptions as $opt)
                            <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model.live="splits.{{ $i }}.amount" class="col-span-4" placeholder="0.00" data-test="split-amount" />
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeSplit({{ $i }})" class="col-span-1" />
                </div>
            @endforeach

            <div class="flex items-center justify-between text-sm">
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="addSplit" data-test="split-add">{{ __('Add line') }}</flux:button>
                <span class="font-mono {{ $splitSum === $splitTargetCents ? 'text-green-600 dark:text-green-500' : 'text-muted-foreground' }}" data-test="split-remaining">
                    {{ __('Allocated :a of :t', ['a' => number_format($splitSum / 100, 2), 't' => number_format($splitTargetCents / 100, 2)]) }}
                </span>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="cancelSplit">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveSplit" :disabled="$splitSum !== $splitTargetCents" data-test="split-save">{{ __('Add split') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
