{{--
    Dropdown for <x-account-combo>, rendered inside the parent's `accountCombo`
    Alpine scope. Kept in its own file so the parent's <flux:input> is followed
    by one short component tag — a tall pile of markup after a Flux tag trips
    catastrophic backtracking in Blade's component-tag compiler.

    position:fixed (placed from the input's rect in JS) so it escapes the line
    table's overflow-x-auto clip. wire:ignore keeps Livewire's morph from
    resetting the inline position; its rows are rendered by Alpine only while
    open. mousedown is prevented so clicking a row never blurs the input.
--}}
<div
    x-ref="list"
    x-show="open"
    x-cloak
    wire:ignore
    data-escape-guard
    role="listbox"
    x-bind:id="listId"
    x-on:mousedown.prevent
    class="fixed z-50 overflow-y-auto overscroll-contain rounded-md border border-border bg-card py-1 text-sm shadow-lg"
    data-test="account-combo-list"
>
    <template x-for="(row, idx) in rendered" :key="row.key">
        <div
            role="option"
            x-bind:id="optionId(idx)"
            x-bind:aria-selected="row.selected ? 'true' : 'false'"
            x-bind:data-active="active === idx"
            x-bind:class="active === idx ? 'bg-muted' : ''"
            x-on:mousemove="active = idx"
            x-on:click="pick(row.item)"
            class="flex cursor-pointer items-baseline gap-3 px-3 py-1.5"
        >
            <template x-if="row.clear">
                <span class="text-muted-foreground" x-text="row.item.name"></span>
            </template>
            <template x-if="! row.clear">
                <span class="flex min-w-0 grow items-baseline gap-3" x-bind:class="row.selected ? 'font-medium' : ''">
                    <span class="min-w-18 shrink-0 tabular-nums text-muted-foreground" x-show="row.code !== ''">
                        <template x-for="(part, p) in row.codeParts" :key="p"><span x-bind:class="part.match ? 'font-semibold text-foreground' : ''" x-text="part.text"></span></template>
                    </span>
                    <span class="min-w-0">
                        <template x-for="(part, p) in row.nameParts" :key="p"><span x-bind:class="part.match ? 'font-semibold underline decoration-1 underline-offset-2' : ''" x-text="part.text"></span></template>
                    </span>
                </span>
            </template>
        </div>
    </template>
    <div x-show="open && rendered.length === 0" class="px-3 py-2 text-muted-foreground">{{ __('No matching accounts.') }}</div>
</div>
