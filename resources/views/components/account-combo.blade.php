@props([
    'model',
    'options' => 'accounts',
    'live' => true,
    'placeholder' => '—',
    'size' => null,
    'invalid' => null,
])

{{--
    Typeable account picker for a line's "Account" column. Free Flux has no
    combobox, so this replaces the native <select>: one box searches by GL
    number ("24" lists 2400, 2410… first) and by name ("disb", "bmo usd"), with
    arrow keys, Enter, Tab (commits the highlighted match only after typing or
    arrowing) and Escape (cancel). The JS is the `accountCombo` Alpine component
    in resources/js/account-combo.js.

        <x-account-combo.options key="lineAccounts" :options="$this->lineAccountOptions" />
        …
        <x-account-combo model="lines.{{ $i }}.account_id" options="lineAccounts" data-test="line-account" />

    - model:       the Livewire property path. The label is derived from $wire on
                   every render (never held here), so removing or reordering rows
                   can't leave a stale account behind. Picking writes through
                   $wire.$set(), so the server's updated*() hooks still run; the
                   "—" choice sends '' exactly as the select's empty option did.
    - options:     the key of the page's <x-account-combo.options> payload. The
                   chart is emitted once per page, not once per row.
    - live:        false for a deferred binding (what plain wire:model was).
    - placeholder: shown when empty, and the label of the "—" (clear) choice.

    `class` styles the wrapper (e.g. a grid span); every other attribute —
    data-test, data-line-first — lands on the <input>.
--}}
@php($invalid ??= isset($errors) && $errors->has($model))
<div
    x-data="accountCombo"
    data-account-combo
    data-model="{{ $model }}"
    data-options="{{ $options }}"
    data-live="{{ $live ? 'true' : 'false' }}"
    data-placeholder="{{ $placeholder }}"
    {{ $attributes->only('class')->class('relative') }}
>
    <flux:input
        x-ref="input"
        x-bind="comboInput"
        role="combobox"
        aria-autocomplete="list"
        autocomplete="off"
        spellcheck="false"
        :placeholder="$placeholder"
        :size="$size"
        :invalid="$invalid"
        {{ $attributes->except('class') }}
    />

    <x-account-combo.list />
</div>
