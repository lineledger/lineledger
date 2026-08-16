<?php

use App\Actions\Support\PostSupportTicketReply;
use App\Models\SupportTicket;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Support ticket')] class extends Component {
    public SupportTicket $ticket;

    public string $reply = '';

    public function mount(SupportTicket $ticket): void
    {
        abort_unless($ticket->user_id === auth()->id(), 404);

        $this->ticket = $ticket;
        $ticket->markReadFor(auth()->user());
    }

    public function sendReply(PostSupportTicketReply $action): void
    {
        $validated = $this->validate([
            'reply' => ['required', 'string', 'max:5000'],
        ]);

        $action->handle($this->ticket, auth()->user(), $validated['reply'], fromAdmin: false);

        $this->reset('reply');
        $this->ticket->refresh();

        Flux::toast(variant: 'success', text: __('Reply sent.'));
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

<section class="mx-auto w-full max-w-3xl">
    <div class="mb-4">
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('support.index')" wire:navigate>
            {{ __('Back to tickets') }}
        </flux:button>
    </div>

    <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $ticket->subject }}</flux:heading>
            <flux:subheading>{{ __('Ticket #:id', ['id' => $ticket->id]) }}</flux:subheading>
        </div>
        <div class="flex items-center gap-2">
            <flux:badge size="sm" :color="$ticket->type->color()">{{ $ticket->type->label() }}</flux:badge>
            <flux:badge size="sm" :color="$ticket->status->color()">{{ $ticket->status->label() }}</flux:badge>
        </div>
    </div>

    <x-support-thread :messages="$this->thread" />

    <div class="mt-6 rounded-lg border border-border p-4">
        <form wire:submit="sendReply" class="space-y-3">
            <flux:textarea wire:model="reply" :label="__('Add a reply')" rows="4" required data-test="reply-body" />
            <div class="flex justify-end">
                <flux:button variant="primary" type="submit" icon="paper-airplane" data-test="reply-submit">{{ __('Send reply') }}</flux:button>
            </div>
        </form>
    </div>
</section>
