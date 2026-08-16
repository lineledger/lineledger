@props(['messages'])

<div class="space-y-4" data-test="ticket-thread">
    @foreach ($messages as $message)
        <div @class(['flex', 'justify-end' => $message->from_admin]) wire:key="msg-{{ $message->id }}" data-test="ticket-message">
            <div @class([
                'max-w-[85%] rounded-lg px-4 py-3',
                'bg-accent/10 dark:bg-accent/20' => $message->from_admin,
                'bg-muted' => ! $message->from_admin,
            ])>
                <div class="mb-1 flex items-center gap-2 text-xs text-muted-foreground">
                    <span class="font-medium text-foreground">
                        {{ $message->from_admin ? __('Support') : ($message->author?->name ?? __('You')) }}
                    </span>
                    <span>{{ $message->created_at?->diffForHumans() }}</span>
                </div>
                <div class="whitespace-pre-wrap text-sm text-foreground">{{ $message->body }}</div>
            </div>
        </div>
    @endforeach
</div>
