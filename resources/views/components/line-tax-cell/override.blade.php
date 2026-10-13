@props([
    'model',
    'codeId',
    'code' => null,
    'auto' => 0,
    'test' => 'line-tax-override',
])

{{--
    One selected tax code's amount on a line: the code's short name, then an
    <x-amount-input> whose placeholder is the calculated amount. Rendered by
    <x-line-tax-cell /> once per selected code. Keyed by the bound path so a
    morph keeps each input (and anything typed in it) with its own code.
--}}
@php($label = $code?->code ?? __('Tax'))
@php($ariaLabel = __(':tax amount', ['tax' => $label]))
<div class="mt-1 flex items-center gap-1.5" wire:key="{{ $model }}">
    <span class="w-12 shrink-0 truncate text-xs text-muted-foreground" title="{{ $code?->name ?? $label }}" data-test="{{ $test }}-label">{{ $label }}</span>
    <div class="min-w-0 flex-1">
        <x-amount-input :model="$model" modifiers=".live.debounce.500ms" size="sm"
            class="lg:text-right"
            placeholder="{{ number_format($auto / 100, 2) }}"
            aria-label="{{ $ariaLabel }}"
            data-test="{{ $test }}"
            data-tax-code="{{ $codeId }}" />
    </div>
</div>
