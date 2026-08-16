<?php

use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Site Admin — Support')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    public function mount(): void
    {
        abort_unless(auth()->user()?->site_admin, 404);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function tickets()
    {
        return SupportTicket::query()
            ->with(['owner', 'company'])
            ->withCount(['messages as unread_user_count' => fn ($q) => $q->where('from_admin', false)->whereNull('read_at')])
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('subject', 'like', '%'.$this->search.'%')
                    ->orWhereHas('owner', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%'));
            }))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('last_activity_at')
            ->paginate(25);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return ['all' => __('All statuses')] + SupportTicketStatus::options();
    }
}; ?>

<x-pages::admin.layout
    :heading="__('Support')"
    :subheading="__('Tickets raised by users across the platform.')"
    content-class="max-w-5xl"
>
    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search subject, name or email…') }}"
            icon="magnifying-glass"
            class="sm:max-w-md"
            data-test="ticket-search"
        />
        <flux:select wire:model.live="statusFilter" class="sm:max-w-[200px]" data-test="ticket-status-filter">
            @foreach ($this->statusOptions as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->tickets as $ticket)
            <a href="{{ route('admin.support.show', $ticket) }}" wire:navigate
                class="block rounded-lg border border-border p-4 hover:bg-muted" wire:key="ticket-card-{{ $ticket->id }}" data-test="admin-ticket-row">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ $ticket->subject }}</div>
                        <div class="text-sm text-muted-foreground">{{ $ticket->owner?->name }} · {{ $ticket->owner?->email }}</div>
                    </div>
                    <flux:badge size="sm" :color="$ticket->status->color()">{{ $ticket->status->label() }}</flux:badge>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <flux:badge size="sm" :color="$ticket->type->color()">{{ $ticket->type->label() }}</flux:badge>
                    @if ($ticket->company)
                        <span>{{ $ticket->company->name }}</span>
                    @endif
                    @if ($ticket->unread_user_count > 0)
                        <flux:badge size="sm" color="amber">{{ __(':n new', ['n' => $ticket->unread_user_count]) }}</flux:badge>
                    @endif
                    <span>{{ ($ticket->last_activity_at ?? $ticket->created_at)?->diffForHumans() }}</span>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No tickets found.') }}</flux:text>
        @endforelse
    </div>

    {{-- Desktop: full table --}}
    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Subject') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('From') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Updated') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->tickets as $ticket)
                    <tr wire:key="ticket-row-{{ $ticket->id }}" class="cursor-pointer hover:bg-muted"
                        onclick="window.location='{{ route('admin.support.show', $ticket) }}'" data-test="admin-ticket-row">
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                @if ($ticket->unread_user_count > 0)
                                    <span class="size-2 shrink-0 rounded-full bg-amber-500" title="{{ __('Unread reply') }}"></span>
                                @endif
                                <a href="{{ route('admin.support.show', $ticket) }}" wire:navigate class="font-medium hover:underline">{{ $ticket->subject }}</a>
                            </div>
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            <div>{{ $ticket->owner?->name }}</div>
                            <div class="text-xs">{{ $ticket->company?->name }}</div>
                        </td>
                        <td class="px-4 py-2"><flux:badge size="sm" :color="$ticket->type->color()">{{ $ticket->type->label() }}</flux:badge></td>
                        <td class="px-4 py-2"><flux:badge size="sm" :color="$ticket->status->color()">{{ $ticket->status->label() }}</flux:badge></td>
                        <td class="px-4 py-2 text-muted-foreground">{{ ($ticket->last_activity_at ?? $ticket->created_at)?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No tickets found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->tickets->links() }}</div>
</x-pages::admin.layout>
