<?php

use App\Actions\Support\OpenSupportTicket;
use App\Enums\SupportTicketType;
use App\Models\SupportTicket;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Support')] class extends Component {
    public string $subject = '';

    public string $type = 'general';

    public string $body = '';

    public function save(OpenSupportTicket $action): void
    {
        $validated = $this->validate([
            'subject' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(SupportTicketType::class)],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $ticket = $action->handle(
            auth()->user(),
            $validated['subject'],
            SupportTicketType::from($validated['type']),
            $validated['body'],
            auth()->user()->currentCompany,
        );

        $this->reset('subject', 'body');
        $this->type = 'general';

        Flux::modal('ticket-form')->close();
        Flux::toast(variant: 'success', text: __('Ticket submitted. We usually reply within 24 hours.'));

        $this->redirectRoute('support.show', $ticket, navigate: true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, SupportTicket>
     */
    #[Computed]
    public function tickets()
    {
        return auth()->user()->supportTickets()
            ->withCount(['messages as unread_admin_count' => fn ($q) => $q->where('from_admin', true)->whereNull('read_at')])
            ->orderByDesc('last_activity_at')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function typeOptions(): array
    {
        return SupportTicketType::options();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Support') }}</flux:heading>
            <flux:subheading>{{ __('Ask a question, report an issue, or request a feature.') }}</flux:subheading>
        </div>

        <flux:modal.trigger name="ticket-form">
            <flux:button variant="primary" icon="plus" data-test="new-ticket-button">{{ __('New ticket') }}</flux:button>
        </flux:modal.trigger>
    </div>

    @if ($this->tickets->isEmpty())
        <div class="rounded-lg border border-dashed border-border py-16 text-center">
            <flux:icon name="lifebuoy" class="mx-auto size-8 text-muted-foreground" />
            <flux:heading class="mt-3">{{ __('No tickets yet') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __('Open a ticket and our team will get back to you, usually within 24 hours.') }}</flux:subheading>
            <flux:modal.trigger name="ticket-form">
                <flux:button variant="primary" icon="plus" class="mt-4">{{ __('New ticket') }}</flux:button>
            </flux:modal.trigger>
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-border">
            <ul class="divide-y divide-border">
                @foreach ($this->tickets as $ticket)
                    <li wire:key="ticket-{{ $ticket->id }}">
                        <a href="{{ route('support.show', $ticket) }}" wire:navigate
                            class="flex items-center gap-3 px-4 py-3 hover:bg-muted" data-test="ticket-row">
                            @if ($ticket->unread_admin_count > 0)
                                <span class="size-2 shrink-0 rounded-full bg-sky-500" title="{{ __('New reply') }}" data-test="ticket-unread-dot"></span>
                            @else
                                <span class="size-2 shrink-0"></span>
                            @endif

                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">{{ $ticket->subject }}</div>
                                <div class="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                                    <flux:badge size="sm" :color="$ticket->type->color()">{{ $ticket->type->label() }}</flux:badge>
                                    <span>{{ __('Updated :when', ['when' => ($ticket->last_activity_at ?? $ticket->created_at)?->diffForHumans()]) }}</span>
                                </div>
                            </div>

                            <flux:badge size="sm" :color="$ticket->status->color()">{{ $ticket->status->label() }}</flux:badge>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <flux:modal name="ticket-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('New support ticket') }}</flux:heading>
                <flux:subheading>{{ __('Tickets are reviewed and typically answered within 24 hours.') }}</flux:subheading>
            </div>

            <flux:input wire:model="subject" :label="__('Subject')" required maxlength="150" data-test="ticket-subject" />

            <flux:select wire:model="type" :label="__('Type')" data-test="ticket-type">
                @foreach ($this->typeOptions as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:textarea wire:model="body" :label="__('How can we help?')" rows="6" required data-test="ticket-body" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="ticket-submit">{{ __('Submit ticket') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
