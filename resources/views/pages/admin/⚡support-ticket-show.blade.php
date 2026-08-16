<?php

use App\Actions\Support\PostSupportTicketReply;
use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Site Admin — Ticket')] class extends Component {
    public SupportTicket $ticket;

    public string $reply = '';

    public function mount(SupportTicket $ticket): void
    {
        abort_unless(auth()->user()?->site_admin, 404);

        $this->ticket = $ticket->load('owner', 'company');
        $ticket->markReadFor(auth()->user());
    }

    public function sendReply(PostSupportTicketReply $action): void
    {
        abort_unless(auth()->user()?->site_admin, 404);

        $validated = $this->validate([
            'reply' => ['required', 'string', 'max:5000'],
        ]);

        $action->handle($this->ticket, auth()->user(), $validated['reply'], fromAdmin: true);

        $this->reset('reply');
        $this->ticket->refresh();

        Flux::toast(variant: 'success', text: __('Reply sent to :name.', ['name' => $this->ticket->owner?->name ?? __('the user')]));
    }

    public function markResolved(): void
    {
        abort_unless(auth()->user()?->site_admin, 404);

        $this->ticket->forceFill(['status' => SupportTicketStatus::Resolved])->save();

        Flux::toast(variant: 'success', text: __('Ticket marked resolved.'));
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\SupportTicketMessage>
     */
    #[Computed]
    public function thread()
    {
        return $this->ticket->messages()->with('author')->get();
    }
}; ?>

<x-pages::admin.layout
    :heading="$ticket->subject"
    :subheading="__('Ticket #:id · from :name', ['id' => $ticket->id, 'name' => $ticket->owner?->name])"
    content-class="max-w-3xl"
>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2">
            <flux:badge size="sm" :color="$ticket->type->color()">{{ $ticket->type->label() }}</flux:badge>
            <flux:badge size="sm" :color="$ticket->status->color()">{{ $ticket->status->label() }}</flux:badge>
            @if ($ticket->company)
                <flux:badge size="sm" color="zinc">{{ $ticket->company->name }}</flux:badge>
            @endif
        </div>
        <div class="flex items-center gap-2">
            <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.support')" wire:navigate>
                {{ __('Back') }}
            </flux:button>
            @unless ($ticket->status === \App\Enums\SupportTicketStatus::Resolved)
                <flux:button variant="filled" size="sm" icon="check" wire:click="markResolved" data-test="resolve-button">
                    {{ __('Mark resolved') }}
                </flux:button>
            @endunless
        </div>
    </div>

    <div class="mb-2 text-sm text-muted-foreground">{{ $ticket->owner?->email }}</div>

    <x-support-thread :messages="$this->thread" />

    <div class="mt-6 rounded-lg border border-border p-4">
        <form wire:submit="sendReply" class="space-y-3">
            <flux:textarea wire:model="reply" :label="__('Reply to :name', ['name' => $ticket->owner?->name])" rows="4" required data-test="admin-reply-body" />
            <div class="flex justify-end">
                <flux:button variant="primary" type="submit" icon="paper-airplane" data-test="admin-reply-submit">{{ __('Send reply') }}</flux:button>
            </div>
        </form>
    </div>
</x-pages::admin.layout>
