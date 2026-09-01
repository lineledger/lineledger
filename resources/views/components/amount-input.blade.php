@props([
    'model',
    'modifiers' => '.live',
])

{{--
    Money input with an in-cell calculator. Typing an expression such as
    "1050+52.50", "100*1.13" or "250/4" surfaces a running "tape" dropdown of
    each operand and operator (+ − × ÷ with standard precedence; x / × / ÷ are
    accepted aliases); pressing Enter (or blurring) collapses it to the final
    decimal value and syncs that to the bound Livewire property. Plain amounts
    behave like a normal input. The parser is resources/js/amount-expression.js;
    the `amountCalculator` Alpine component (resources/js/app.js) wires it to
    the input, and the dropdown is <x-amount-input.tape />.

    The Livewire binding defaults to `wire:model.live`. Pass `modifiers` to change
    it — e.g. modifiers="" for deferred binding, or modifiers=".live.debounce.500ms".
    It is merged into the attribute bag below (NOT written on the tag) because a
    dynamic attribute name can't be echoed inside a tag, and any Blade directive
    inside a <flux:input> tag's attribute list trips catastrophic backtracking in
    Blade's component-tag compiler — the tag silently fails to compile and the
    field disappears. Caller attributes (class, data-test, :label, …) ride the bag
    too. See [[blade-directive-in-flux-tag-backtracking]].
--}}
@php($attributes = $attributes->merge(['wire:model'.$modifiers => $model]))
<div x-data="amountCalculator" @click.outside="showTape = false" class="relative">
    <flux:input
        x-ref="input"
        inputmode="decimal"
        autocomplete="off"
        {{ $attributes }}
    />

    <x-amount-input.tape />
</div>
