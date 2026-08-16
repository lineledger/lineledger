@props([
    'field',
    'currentField',
    'currentDir',
    'label',
    'align' => 'left',
    'action' => 'sortBy',
])

@php
    $isActive = $currentField === $field;
    $nextDir = $isActive && $currentDir === 'asc' ? 'desc' : 'asc';
    $iconName = $isActive ? ($currentDir === 'asc' ? 'chevron-up' : 'chevron-down') : 'chevron-up-down';
    $iconClass = $isActive ? 'size-3 text-zinc-700 dark:text-zinc-200' : 'size-3 text-zinc-400';
    $justify = $align === 'right' ? 'justify-end' : 'justify-start';
@endphp

<button
    type="button"
    wire:click="{{ $action }}('{{ $field }}')"
    {{ $attributes->merge(['class' => "flex w-full items-center gap-1 {$justify} font-medium hover:text-zinc-900 dark:hover:text-white"]) }}
    data-test="sort-{{ $field }}"
    aria-label="{{ __('Sort by :label :dir', ['label' => $label, 'dir' => $nextDir === 'asc' ? __('ascending') : __('descending')]) }}"
>
    <span>{{ $label }}</span>
    <flux:icon :name="$iconName" :class="$iconClass" />
</button>
