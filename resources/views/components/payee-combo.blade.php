{{--
    Payee picker for the cheque and expense forms. Host must use
    App\Livewire\Concerns\ManagesPayeeCombo (selectPayee / startNewOtherName /
    createOtherName / clearPayee, payee_query / payee_creating / new_payee_name).

    Four states, one at a time:
      selected  — a linked contact: name + a badge per held role + clear
      unlinked  — a legacy free-text payee (no contact): name + "Not linked" + clear
      creating  — quick-adding an Other name: prefilled input, ✓ / ✕
      search    — type to filter; pick with mouse or arrow keys + Enter

    The wrapper's wire:key carries the state so Livewire swaps the element (and
    Alpine re-initialises `open`) whenever the field flips state, even though
    wire:ignore.self keeps the wrapper's own attributes out of the morph.
--}}
@props([
    'label' => 'Payee',
    'placeholder' => null,
    'options' => [],
    'selectedId' => null,
    'selectedName' => null,
    'selectedRoles' => [],
    'legacyName' => '',
    'query' => '',
    'creating' => false,
    'newName' => '',
    'createLinks' => [],
    'dataTest' => 'payee-combo',
    'required' => false,
])

@php
    $placeholder ??= __('Search vendors, customers, employees and other names…');
    $resolver = app(\App\Support\Contacts\ContactLinkResolver::class);
    $trimmed = trim((string) $query);
    // The search spans every role, so an exact match of any kind blocks adding
    // an Other name of the same name — pick the existing contact instead.
    $hasExactMatch = $trimmed !== '' && collect($options)->contains(
        fn ($o) => mb_strtolower($o->display_name) === mb_strtolower($trimmed)
    );
    $state = match (true) {
        (bool) $selectedId => 'selected',
        (bool) $creating => 'creating',
        trim((string) $legacyName) !== '' => 'unlinked',
        default => 'search',
    };
@endphp

<flux:field>
    <flux:label>{{ $label }}</flux:label>

    <div
        x-data="{
            open: false,
            active: -1,
            items() { return Array.from($root.querySelectorAll('[data-payee-nav]')); },
            move(delta) {
                this.open = true;
                const n = this.items().length;
                if (n === 0) return;
                this.active = (this.active + delta + n) % n;
                this.highlight();
            },
            highlight() {
                this.items().forEach((el, i) => el.classList.toggle('bg-muted', i === this.active));
                this.items()[this.active]?.scrollIntoView({ block: 'nearest' });
            },
            enter() {
                const items = this.items();
                if (! this.open || items.length === 0) return;
                (items[this.active] ?? items[0]).click();
            },
        }"
        @click.outside="open = false"
        @keydown.escape.window="open = false"
        class="relative"
        wire:ignore.self
        wire:key="{{ $dataTest }}-{{ $state }}"
        data-test="{{ $dataTest }}"
        data-state="{{ $state }}"
    >
        @if ($state === 'selected')
            <div class="flex min-h-10 items-center justify-between gap-2 rounded-md border border-border bg-card px-3">
                <span class="flex min-w-0 flex-wrap items-center gap-2">
                    <span class="truncate text-sm" data-test="{{ $dataTest }}-selected">{{ $selectedName }}</span>
                    @foreach ($selectedRoles as $role)
                        <flux:badge size="sm" :color="$role['color']" data-test="{{ $dataTest }}-role">{{ $role['label'] }}</flux:badge>
                    @endforeach
                </span>
                <button
                    type="button"
                    wire:click="clearPayee"
                    class="shrink-0 text-muted-foreground hover:text-foreground"
                    aria-label="{{ __('Clear payee') }}"
                    data-test="{{ $dataTest }}-clear"
                >
                    <flux:icon name="x-mark" class="size-4" />
                </button>
            </div>
        @elseif ($state === 'unlinked')
            <div class="flex min-h-10 items-center justify-between gap-2 rounded-md border border-border bg-card px-3">
                <span class="flex min-w-0 flex-wrap items-center gap-2">
                    <span class="truncate text-sm" data-test="{{ $dataTest }}-unlinked">{{ $legacyName }}</span>
                    <flux:badge size="sm" color="zinc" title="{{ __('Free-text payee from an older draft. Clear it to link a contact.') }}">{{ __('Not linked') }}</flux:badge>
                </span>
                <button
                    type="button"
                    wire:click="clearPayee"
                    class="shrink-0 text-muted-foreground hover:text-foreground"
                    aria-label="{{ __('Clear payee') }}"
                    data-test="{{ $dataTest }}-clear"
                >
                    <flux:icon name="x-mark" class="size-4" />
                </button>
            </div>
        @elseif ($state === 'creating')
            <div class="flex h-10 items-center justify-between gap-2 rounded-md border border-border bg-card px-3">
                <input
                    type="text"
                    wire:model="new_payee_name"
                    wire:key="{{ $dataTest }}-new-name-input"
                    value="{{ $newName }}"
                    x-init="$el.value = @js($newName); $nextTick(() => $el.focus())"
                    x-on:keydown.enter.prevent="$wire.createOtherName($el.value)"
                    placeholder="{{ __('New other name') }}"
                    maxlength="255"
                    class="block w-full bg-transparent text-sm text-foreground focus:outline-none"
                    data-test="{{ $dataTest }}-new-name"
                />
                <button
                    type="button"
                    wire:click="createOtherName"
                    class="shrink-0 text-emerald-600 hover:text-emerald-700"
                    aria-label="{{ __('Add other name') }}"
                    data-test="{{ $dataTest }}-create"
                >
                    <flux:icon name="check" class="size-4" />
                </button>
                <button
                    type="button"
                    wire:click="clearPayee"
                    class="shrink-0 text-muted-foreground hover:text-foreground"
                    aria-label="{{ __('Cancel new other name') }}"
                    data-test="{{ $dataTest }}-cancel-new"
                >
                    <flux:icon name="x-mark" class="size-4" />
                </button>
            </div>
        @else
            <flux:input
                wire:model.live.debounce.150ms="payee_query"
                wire:key="{{ $dataTest }}-search-input"
                placeholder="{{ $placeholder }}"
                x-on:focus="open = true"
                x-on:focus.window="open && $wire.$refresh()"
                x-on:input="active = -1"
                x-on:keydown.arrow-down.prevent="move(1)"
                x-on:keydown.arrow-up.prevent="move(-1)"
                x-on:keydown.enter.prevent="enter()"
                autocomplete="off"
                :required="$required"
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
                        wire:click="selectPayee({{ $opt->id }})"
                        wire:key="{{ $dataTest }}-option-{{ $opt->id }}"
                        data-payee-nav
                        class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-muted"
                        data-test="{{ $dataTest }}-option"
                    >
                        <span class="truncate">{{ $opt->display_name }}</span>
                        <span class="flex shrink-0 items-center gap-1">
                            @foreach ($resolver->roleLabels($opt) as $role)
                                <flux:badge size="sm" :color="$role['color']" data-test="{{ $dataTest }}-role">{{ $role['label'] }}</flux:badge>
                            @endforeach
                        </span>
                    </button>
                @empty
                    @if ($trimmed === '')
                        <div class="px-3 py-2 text-sm text-muted-foreground">{{ __('Start typing to search.') }}</div>
                    @endif
                @endforelse

                @if ($trimmed !== '' && ! $hasExactMatch)
                    <button
                        type="button"
                        wire:click="startNewOtherName"
                        data-payee-nav
                        class="block w-full border-t border-border px-3 py-2 text-left text-sm font-medium text-blue-600 hover:bg-muted dark:text-blue-400"
                        data-test="{{ $dataTest }}-add-other-name"
                    >
                        + {{ __('Add ":query" as Other name', ['query' => $trimmed]) }}
                    </button>

                    {{-- Plain anchors on purpose: the hand-off opens a new tab, so the
                         in-progress cheque stays put and there is nothing for
                         wire:navigate to do. --}}
                    @foreach ($createLinks as $link)
                        <a
                            href="{{ $link['url'] }}"
                            target="_blank"
                            rel="noopener"
                            class="block w-full px-3 py-2 text-left text-sm text-muted-foreground hover:bg-muted hover:text-foreground"
                            data-test="{{ $dataTest }}-{{ $link['dataTest'] }}"
                        >
                            {{ __('Create ":query" as a new :type', ['query' => $trimmed, 'type' => $link['label']]) }} ↗
                        </a>
                    @endforeach
                @endif
            </div>
        @endif
    </div>

    @error('payee_contact_id') <flux:error :message="$message" /> @enderror
    @error('payee_name') <flux:error :message="$message" /> @enderror
    @error('new_payee_name') <flux:error :message="$message" /> @enderror
</flux:field>
