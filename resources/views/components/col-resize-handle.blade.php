@props([
    'edge' => 'end',
])

{{--
    Drag handle for one resizable line-table column. Goes inside a header cell
    shaped like
        <th data-col="account" data-col-min="120" wire:ignore.self class="relative w-72 …">
    in a <table x-resizable-columns="'<storage-key>'" wire:ignore.self>; the
    directive (resources/js/resizable-columns.js) does the dragging, and a
    double-click resets the column to its default width.

    edge="end" (default) sits on the cell's right edge — for columns LEFT of
    the flexible Description column. edge="start" sits on its left edge — for
    columns to the right of it — so the border under the pointer always follows
    the pointer. Desktop only: below lg the line table turns into cards. It's
    server markup, so Livewire morphs keep it; aria-hidden because it's a
    pointer-only convenience.
--}}
@php($onStart = $edge === 'start')
<span
    data-col-resize-handle="{{ $onStart ? 'start' : 'end' }}"
    aria-hidden="true"
    title="{{ __('Drag to resize. Double-click to reset.') }}"
    {{ $attributes->class([
        'group/col-resize absolute inset-y-0 z-10 hidden w-3 cursor-col-resize touch-none select-none lg:block',
        '-left-1.5' => $onStart,
        '-right-1.5' => ! $onStart,
    ]) }}
>
    <span class="pointer-events-none absolute inset-y-1.5 left-1/2 w-0.5 -translate-x-1/2 rounded-full bg-zinc-300 transition-colors group-hover/col-resize:bg-accent group-data-resizing/col-resize:bg-accent dark:bg-zinc-600"></span>
</span>
