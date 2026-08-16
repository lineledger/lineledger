@props([
    'index',
    'addLabel' => 'customer',
    'placeholder' => 'Search or type to add new…',
    'options' => [],
    'selectedId' => null,
    'selectedName' => null,
    'query' => '',
    'creating' => false,
    'dataTest' => 'line-contact-combo',
])

@php
    $trimmed = trim((string) $query);
    $hasExactMatch = $trimmed !== '' && collect($options)->contains(
        fn ($o) => mb_strtolower($o->display_name) === mb_strtolower($trimmed)
    );
@endphp

<div
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="relative mt-2"
    wire:ignore.self
    wire:key="line-contact-{{ $index }}"
    data-test="{{ $dataTest }}"
>
    @if ($selectedId)
        <div class="flex h-10 items-center justify-between rounded-md border border-border bg-card px-3">
            <span class="truncate text-sm" data-test="{{ $dataTest }}-selected">{{ $selectedName }}</span>
            <button
                type="button"
                wire:click="clearLineContact({{ $index }})"
                class="text-muted-foreground hover:text-foreground"
                aria-label="{{ __('Clear') }}"
                data-test="{{ $dataTest }}-clear"
            >
                <flux:icon name="x-mark" class="size-4" />
            </button>
        </div>
    @elseif ($creating)
        <div class="flex h-10 items-center justify-between gap-2 rounded-md border border-border bg-card px-3">
            <input
                type="text"
                wire:model="lines.{{ $index }}.new_contact_name"
                placeholder="{{ __('New :name name', ['name' => $addLabel]) }}"
                class="block w-full bg-transparent text-sm text-foreground focus:outline-none"
                data-test="{{ $dataTest }}-new-name"
                autofocus
            />
            <button
                type="button"
                wire:click="clearLineContact({{ $index }})"
                class="shrink-0 text-muted-foreground hover:text-foreground"
                aria-label="{{ __('Cancel new :name', ['name' => $addLabel]) }}"
                data-test="{{ $dataTest }}-cancel-new"
            >
                <flux:icon name="x-mark" class="size-4" />
            </button>
        </div>
    @else
        <flux:input
            wire:model.live.debounce.150ms="lines.{{ $index }}.contact_query"
            placeholder="{{ $placeholder }}"
            @focus="open = true"
            @keydown.enter.prevent=""
            autocomplete="off"
            data-test="{{ $dataTest }}-search"
        />

        <div
            x-show="open"
            x-cloak
            x-transition.opacity.duration.100ms
            class="absolute left-0 right-0 top-full z-20 mt-1 max-h-60 overflow-auto rounded-md border border-border bg-card py-1 shadow-lg"
        >
            @forelse ($options as $opt)
                <button
                    type="button"
                    wire:click="selectLineContact({{ $index }}, {{ $opt->id }})"
                    class="block w-full px-3 py-2 text-left text-sm hover:bg-muted"
                    data-test="{{ $dataTest }}-option"
                >
                    {{ $opt->display_name }}
                </button>
            @empty
                @if ($trimmed === '')
                    <div class="px-3 py-2 text-sm text-muted-foreground">{{ __('Start typing to search.') }}</div>
                @endif
            @endforelse

            @if ($trimmed !== '' && ! $hasExactMatch)
                <button
                    type="button"
                    wire:click="startNewLineContact({{ $index }})"
                    class="block w-full border-t border-border px-3 py-2 text-left text-sm font-medium text-blue-600 hover:bg-muted dark:text-blue-400"
                    data-test="{{ $dataTest }}-add-new"
                >
                    + {{ __('Add ":query" as new :name', ['query' => $trimmed, 'name' => $addLabel]) }}
                </button>
            @endif
        </div>
    @endif

    @error('lines.' . $index . '.contact_id') <flux:error :message="$message" /> @enderror
    @error('lines.' . $index . '.new_contact_name') <flux:error :message="$message" /> @enderror
</div>
