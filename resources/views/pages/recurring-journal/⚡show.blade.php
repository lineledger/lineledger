<?php

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\RecurringJournalEntry;
use App\Services\Recurring\RecurringJournalEntryGenerator;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Recurring journal entry')] class extends Component {
    public Company $company;

    public RecurringJournalEntry $recurring;

    public function mount(Company $company, RecurringJournalEntry $recurring): void
    {
        $this->company = $company;
        $this->recurring = $recurring->load(['lines.account']);
    }

    public function pauseSchedule(): void
    {
        $this->recurring->update(['is_active' => false]);
        $this->recurring->refresh();
        Flux::toast(variant: 'success', text: __('Schedule paused.'));
    }

    public function resumeSchedule(): void
    {
        $this->recurring->update(['is_active' => true, 'paused_reason' => null]);
        $this->recurring->refresh();
        Flux::toast(variant: 'success', text: __('Schedule resumed.'));
    }

    public function generateNow(RecurringJournalEntryGenerator $generator): void
    {
        try {
            $entry = $generator->generateOne($this->recurring);
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->recurring->refresh();

        $this->redirectRoute('journal.edit', ['company' => $this->company->slug, 'entry' => $entry->id], navigate: true);
    }

    public function deleteSchedule(): void
    {
        $this->recurring->delete();
        Flux::toast(variant: 'success', text: __('Schedule deleted.'));
        $this->redirectRoute('recurring-journal.index', ['company' => $this->company->slug], navigate: true);
    }

    #[Computed]
    public function generated()
    {
        return JournalEntry::query()
            ->where('recurring_journal_entry_id', $this->recurring->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function resumable(): bool
    {
        return ! $this->recurring->is_active && $this->recurring->next_run_date !== null;
    }

    #[Computed]
    public function totalDebitsCents(): int
    {
        return (int) $this->recurring->lines->sum('debit_cents');
    }

    #[Computed]
    public function totalCreditsCents(): int
    {
        return (int) $this->recurring->lines->sum('credit_cents');
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $recurring->name ?: __('Untitled schedule') }}</flux:heading>
            <flux:subheading>{{ __('Recurring journal entry') }}{{ $recurring->memo ? ' — '.$recurring->memo : '' }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($recurring->is_active)
                <flux:button variant="primary" class="!hidden lg:!inline-flex" icon="bolt" wire:click="generateNow" data-test="generate-now-button">{{ __('Generate now') }}</flux:button>
            @endif

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="recurring-journal-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if ($recurring->is_active)
                        <flux:menu.item class="lg:hidden" icon="bolt" wire:click="generateNow" data-test="generate-now-menu-item">{{ __('Generate now') }}</flux:menu.item>
                    @endif
                    <flux:menu.item icon="pencil-square" :href="route('recurring-journal.edit', ['company' => $company->slug, 'recurring' => $recurring->id])" wire:navigate data-test="edit-recurring-journal-button">
                        {{ __('Edit') }}
                    </flux:menu.item>
                    @if ($recurring->is_active)
                        <flux:menu.item icon="pause" wire:click="pauseSchedule" data-test="pause-button">{{ __('Pause') }}</flux:menu.item>
                    @elseif ($this->resumable)
                        <flux:menu.item icon="play" wire:click="resumeSchedule" data-test="resume-button">{{ __('Resume') }}</flux:menu.item>
                    @endif
                    <flux:menu.separator />
                    <flux:menu.item icon="trash" variant="danger" wire:click="deleteSchedule" wire:confirm="{{ __('Delete this recurring schedule?') }}" data-test="delete-button">{{ __('Delete') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @if ($recurring->paused_reason)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6" :heading="__('Paused — needs attention')">
            {{ $recurring->paused_reason }}
        </flux:callout>
    @endif

    <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        <div>
            <flux:text size="sm" class="text-muted-foreground">{{ __('Frequency') }}</flux:text>
            <flux:heading>{{ $recurring->frequency->label() }}@if ($recurring->frequency->usesDayOfMonth() && ! $recurring->scheduleDayAnchor()->usesDayOfMonth()) · {{ $recurring->scheduleDayAnchor()->shortLabel() }}@endif</flux:heading>
        </div>
        <div>
            <flux:text size="sm" class="text-muted-foreground">{{ __('Next run') }}</flux:text>
            <flux:heading>{{ $recurring->next_run_date?->toDateString() ?? '—' }}</flux:heading>
        </div>
        <div>
            <flux:text size="sm" class="text-muted-foreground">{{ __('Generated') }}</flux:text>
            <flux:heading>{{ $recurring->occurrences_generated }}</flux:heading>
        </div>
        <div>
            <flux:text size="sm" class="text-muted-foreground">{{ __('Status') }}</flux:text>
            <flux:heading>
                @if ($recurring->paused_reason)
                    {{ __('Needs attention') }}
                @elseif ($recurring->hasEnded())
                    {{ __('Ended') }}
                @elseif (! $recurring->is_active)
                    {{ __('Paused') }}
                @else
                    {{ __('Active') }}
                @endif
            </flux:heading>
        </div>
    </div>

    <flux:separator :text="__('Template lines')" class="mb-4" />

    <div class="mb-8 overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Memo') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Debit') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Credit') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($recurring->lines as $line)
                    <tr>
                        <td class="px-4 py-2">{{ optional($line->account)->code }} — {{ optional($line->account)->name }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $line->memo }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $line->debit_cents > 0 ? number_format($line->debit_cents / 100, 2) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $line->credit_cents > 0 ? number_format($line->credit_cents / 100, 2) : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr>
                    <td colspan="2" class="px-4 py-2 text-right font-medium">{{ __('Totals') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($this->totalDebitsCents / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($this->totalCreditsCents / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <flux:separator :text="__('Generated entries')" class="mb-4" />

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Number') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->generated as $doc)
                    <tr class="hover:bg-muted">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $doc->entry_date->toDateString() }}</td>
                        <td class="px-4 py-2 font-mono">
                            <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $doc->id]) }}" wire:navigate class="underline">{{ $doc->entry_no }}</a>
                        </td>
                        <td class="px-4 py-2">
                            @if ($doc->isVoided())
                                <flux:badge size="sm" color="zinc">{{ __('Voided') }}</flux:badge>
                            @elseif ($doc->isPosted())
                                <flux:badge size="sm" color="green">{{ __('Posted') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">{{ __('Draft') }}</flux:badge>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-muted-foreground">{{ __('Nothing generated yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
