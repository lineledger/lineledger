<?php

use App\Models\AccountingAuditLog;
use App\Models\JournalEntry;
use Illuminate\Support\Collection;
use Livewire\Component;

new class extends Component {
    public string $auditableType;

    public int $auditableId;

    public string $title = 'Audit trail';

    public function mount(string $auditableType, int $auditableId, string $title = 'Audit trail'): void
    {
        $this->auditableType = $auditableType;
        $this->auditableId = $auditableId;
        $this->title = $title;
    }

    /**
     * @return Collection<int, AccountingAuditLog>
     */
    public function entries(): Collection
    {
        return AccountingAuditLog::query()
            ->withoutGlobalScopes()
            ->with('actor')
            ->where(function ($q) {
                $q->where(function ($q) {
                    $q->where('auditable_type', $this->auditableType)
                        ->where('auditable_id', $this->auditableId);
                });

                // When viewing a JE, also include events recorded against
                // source documents (Invoice, Bill, etc.) that touch this JE
                // — their journal_entry_id column points here.
                if ($this->isJournalEntry()) {
                    $q->orWhere('journal_entry_id', $this->auditableId);
                }
            })
            ->orderByDesc('sequence')
            ->get();
    }

    protected function isJournalEntry(): bool
    {
        $journalEntryMorph = (new JournalEntry)->getMorphClass();

        return $this->auditableType === $journalEntryMorph
            || $this->auditableType === JournalEntry::class;
    }
}; ?>

<section class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
    <flux:heading size="lg" class="mb-3">{{ __($title) }}</flux:heading>

    @php($entries = $this->entries())

    @if ($entries->isEmpty())
        <flux:subheading>{{ __('No audit events recorded yet.') }}</flux:subheading>
    @else
        <ol class="space-y-3">
            @foreach ($entries as $entry)
                <li class="flex items-start gap-3 border-l-2 border-zinc-200 pl-3 dark:border-zinc-700">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <flux:badge color="zinc" size="sm">#{{ $entry->sequence }}</flux:badge>
                            <span class="font-medium">{{ $entry->action->value }}</span>
                            <span class="text-xs text-zinc-500">
                                {{ $entry->recorded_at->toDayDateTimeString() }}
                            </span>
                        </div>
                        <div class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                            @if ($entry->actor)
                                {{ $entry->actor->name }}
                            @else
                                <em>{{ __('System') }}</em>
                            @endif
                            @if ($entry->actor_ip)
                                &middot; {{ $entry->actor_ip }}
                            @endif
                        </div>
                        <details class="mt-1">
                            <summary class="cursor-pointer text-xs text-zinc-500 hover:underline">{{ __('Payload') }}</summary>
                            <pre class="mt-2 max-h-64 overflow-auto rounded bg-zinc-50 p-2 text-xs dark:bg-zinc-800">{{ json_encode($entry->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
