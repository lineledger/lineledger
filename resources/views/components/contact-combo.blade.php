@props([
    'label' => 'Customer',
    'placeholder' => 'Search or type to add new…',
    'addLabel' => 'customer',
    'options' => [],
    'selectedId' => null,
    'selectedName' => null,
    'query' => '',
    'creating' => false,
    'newName' => '',
    'dataTest' => 'contact-combo',
])

@php
    $trimmed = trim((string) $query);
    $hasExactMatch = $trimmed !== '' && collect($options)->contains(
        fn ($o) => mb_strtolower($o->display_name) === mb_strtolower($trimmed)
    );
@endphp

<flux:field>
    <flux:label>{{ $label }}</flux:label>

    <div
        x-data="{ open: false }"
        @click.outside="open = false"
        @keydown.escape.window="open = false"
        class="relative"
        wire:ignore.self
        data-test="{{ $dataTest }}"
    >
        @if ($selectedId)
            <div class="flex h-10 items-center justify-between rounded-md border border-border bg-card px-3">
                <span class="truncate text-sm" data-test="{{ $dataTest }}-selected">{{ $selectedName }}</span>
                <button
                    type="button"
                    wire:click="clearContact"
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
                    wire:model="new_contact_name"
                    wire:key="{{ $dataTest }}-new-name-input"
                    value="{{ $newName }}"
                    x-init="$el.value = @js($newName); $nextTick(() => $el.focus())"
                    placeholder="{{ __('New :name name', ['name' => $addLabel]) }}"
                    class="block w-full bg-transparent text-sm text-foreground focus:outline-none"
                    data-test="{{ $dataTest }}-new-name"
                />
                <button
                    type="button"
                    wire:click="clearContact"
                    class="shrink-0 text-muted-foreground hover:text-foreground"
                    aria-label="{{ __('Cancel new :name', ['name' => $addLabel]) }}"
                    data-test="{{ $dataTest }}-cancel-new"
                >
                    <flux:icon name="x-mark" class="size-4" />
                </button>
            </div>
        @else
            <flux:input
                wire:model.live.debounce.150ms="contact_query"
                wire:key="{{ $dataTest }}-search-input"
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
                        wire:click="selectContact({{ $opt->id }})"
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
                        wire:click="startNewContact"
                        class="block w-full border-t border-border px-3 py-2 text-left text-sm font-medium text-blue-600 hover:bg-muted dark:text-blue-400"
                        data-test="{{ $dataTest }}-add-new"
                    >
                        + {{ __('Add ":query" as new :name', ['query' => $trimmed, 'name' => $addLabel]) }}
                    </button>
                @endif
            </div>
        @endif
    </div>

    @error('contact_id') <flux:error :message="$message" /> @enderror
    @error('new_contact_name') <flux:error :message="$message" /> @enderror
</flux:field>
