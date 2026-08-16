<?php

use App\Models\Company;
use App\Models\JournalEntry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Journal')] class extends Component {
    use WithPagination;

    public Company $company;

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    #[Computed]
    public function entries()
    {
        return JournalEntry::query()
            ->with('lines')
            ->when($this->statusFilter === 'draft', fn ($q) => $q->where('is_posted', false))
            ->when($this->statusFilter === 'posted', fn ($q) => $q->where('is_posted', true)->whereNull('voided_at'))
            ->when($this->statusFilter === 'voided', fn ($q) => $q->whereNotNull('voided_at'))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('entry_no', 'like', '%'.$this->search.'%')
                    ->orWhere('memo', 'like', '%'.$this->search.'%');
            }))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Journal Entries') }}</flux:heading>
            <flux:subheading>{{ __('All postings to the general ledger.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('journal.create', ['company' => $company->slug])" wire:navigate data-test="new-journal-button">
            {{ __('New entry') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search entry no or memo…') }}" icon="magnifying-glass" class="sm:max-w-md" />
        <flux:select wire:model.live="statusFilter" class="max-w-[200px]">
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            <flux:select.option value="draft">{{ __('Drafts') }}</flux:select.option>
            <flux:select.option value="posted">{{ __('Posted') }}</flux:select.option>
            <flux:select.option value="voided">{{ __('Voided') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->entries as $entry)
            <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $entry->id]) }}" wire:navigate class="block rounded-lg border border-border p-4" data-test="journal-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono font-medium">{{ $entry->entry_no }}</span>
                    @if ($entry->isVoided())
                        <flux:badge color="zinc" size="sm">{{ __('Voided') }}</flux:badge>
                    @elseif ($entry->isPosted())
                        <flux:badge color="green" size="sm">{{ __('Posted') }}</flux:badge>
                    @else
                        <flux:badge color="amber" size="sm">{{ __('Draft') }}</flux:badge>
                    @endif
                </div>
                <div class="mt-1 truncate text-sm text-muted-foreground">{{ $entry->memo }}</div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-xs text-muted-foreground">{{ $entry->entry_date->toDateString() }}</div>
                    <div class="text-right"><div class="font-mono font-semibold">{{ number_format($entry->totalDebitsCents() / 100, 2) }}</div></div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No entries yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('Entry #') }}</th>
                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('Memo') }}</th>
                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">{{ __('Amount') }}</th>
                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('Status') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->entries as $entry)
                    <tr data-test="journal-row">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $entry->entry_date->toDateString() }}</td>
                        <td class="px-4 py-2 font-mono">{{ $entry->entry_no }}</td>
                        <td class="px-4 py-2 max-w-md truncate">{{ $entry->memo }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($entry->totalDebitsCents() / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @if ($entry->isVoided())
                                <flux:badge color="zinc">{{ __('Voided') }}</flux:badge>
                            @elseif ($entry->isPosted())
                                <flux:badge color="green">{{ __('Posted') }}</flux:badge>
                            @else
                                <flux:badge color="amber">{{ __('Draft') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="eye"
                                :href="route('journal.show', ['company' => $company->slug, 'entry' => $entry->id])"
                                wire:navigate
                                data-test="journal-view"
                            />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No entries yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->entries->links() }}
    </div>
</section>
