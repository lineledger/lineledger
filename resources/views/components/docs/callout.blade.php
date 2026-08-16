@props([
    'type' => 'note',
    'heading' => null,
])

@php
    // Map the doc-friendly callout type to Flux's callout variant, icon, and a
    // default heading so tips, notes, and warnings look consistent everywhere.
    $presets = [
        'tip' => ['variant' => 'success', 'icon' => 'light-bulb', 'heading' => __('Tip')],
        'note' => ['variant' => 'secondary', 'icon' => 'information-circle', 'heading' => __('Note')],
        'warning' => ['variant' => 'warning', 'icon' => 'exclamation-triangle', 'heading' => __('Heads up')],
    ];
    $preset = $presets[$type] ?? $presets['note'];
    $resolvedHeading = $heading ?? $preset['heading'];
@endphp

<flux:callout
    :variant="$preset['variant']"
    :icon="$preset['icon']"
    :heading="$resolvedHeading"
    {{ $attributes->merge(['class' => 'not-prose my-6']) }}
>
    <flux:callout.text>{{ $slot }}</flux:callout.text>
</flux:callout>
