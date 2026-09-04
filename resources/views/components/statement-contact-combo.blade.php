{{--
    Per-row payee picker for bank statement lines (import wizard + For Review).
    Host must use App\Livewire\Concerns\ManagesStatementLineContacts.
--}}
@props([
    'lineId',
    'addLabel' => 'vendor',
    'placeholder' => null,
    'options' => [],
    'selectedId' => null,
    'selectedName' => null,
    'query' => '',
    'creating' => false,
    'newName' => '',
    'dataTest' => 'line-contact-combo',
])

@php
    $placeholder ??= $addLabel === 'customer' ? __('Customer…') : __('Vendor…');
    $trimmed = trim((string) $query);
    $hasExactMatch = $trimmed !== '' && collect($options)->contains(
        fn ($o) => mb_strtolower($o->display_name) === mb_strtolower($trimmed)
    );
@endphp

<div
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="relative min-w-[200px]"
    wire:ignore.self
    wire:key="line-contact-{{ $lineId }}"
    data-test="{{ $dataTest }}"
>
    @if ($selectedId)
        <div class="flex h-8 items-center justify-between gap-2 rounded-md border border-border bg-card px-2">
            <span class="truncate text-xs" data-test="{{ $dataTest }}-selected">{{ $selectedName ?? __('Contact #:id', ['id' => $selectedId]) }}</span>
            <button
                type="button"
                wire:click="clearLineContact({{ $lineId }})"
                class="shrink-0 text-muted-foreground hover:text-foreground"
                aria-label="{{ __('Clear') }}"
                data-test="{{ $dataTest }}-clear"
            >
                <flux:icon name="x-mark" class="size-3.5" />
            </button>
        </div>
    @elseif ($creating)
        <div class="flex h-8 items-center justify-between gap-1 rounded-md border border-border bg-card px-2">
            <input
                type="text"
                wire:model.live.blur="lineContact.{{ $lineId }}.new_name"
                wire:keydown.enter.prevent="createLineContact({{ $lineId }})"
                placeholder="{{ __('New :name name', ['name' => $addLabel]) }}"
                class="block w-full bg-transparent text-xs text-foreground focus:outline-none"
                data-test="{{ $dataTest }}-new-name"
                autofocus
            />
            <button
                type="button"
                wire:click="createLineContact({{ $lineId }})"
                class="shrink-0 text-emerald-600 hover:text-emerald-700"
                aria-label="{{ __('Create :name', ['name' => $addLabel]) }}"
                data-test="{{ $dataTest }}-create"
            >
                <flux:icon name="check" class="size-3.5" />
            </button>
            <button
                type="button"
                wire:click="clearLineContact({{ $lineId }})"
                class="shrink-0 text-muted-foreground hover:text-foreground"
                aria-label="{{ __('Cancel new :name', ['name' => $addLabel]) }}"
                data-test="{{ $dataTest }}-cancel-new"
            >
                <flux:icon name="x-mark" class="size-3.5" />
            </button>
        </div>
    @else
        <flux:input
            size="sm"
            wire:model.live.debounce.150ms="lineContact.{{ $lineId }}.query"
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
                    wire:click="selectLineContact({{ $lineId }}, {{ $opt->id }})"
                    class="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-xs hover:bg-muted"
                    data-test="{{ $dataTest }}-option"
                >
                    <span class="truncate">{{ $opt->display_name }}</span>
                    @if (($opt->is_employee ?? false) && ! ($opt->is_vendor ?? false))
                        <flux:badge size="sm" color="zinc" data-test="{{ $dataTest }}-role">{{ __('Employee') }}</flux:badge>
                    @endif
                </button>
            @empty
                @if ($trimmed === '')
                    <div class="px-3 py-1.5 text-xs text-muted-foreground">{{ __('Start typing to search.') }}</div>
                @endif
            @endforelse

            @if ($trimmed !== '' && ! $hasExactMatch)
                <button
                    type="button"
                    wire:click="startNewLineContact({{ $lineId }})"
                    class="block w-full border-t border-border px-3 py-1.5 text-left text-xs font-medium text-blue-600 hover:bg-muted dark:text-blue-400"
                    data-test="{{ $dataTest }}-add-new"
                >
                    + {{ __('Add ":query" as new :name', ['query' => $trimmed, 'name' => $addLabel]) }}
                </button>
            @endif
        </div>
    @endif
</div>
