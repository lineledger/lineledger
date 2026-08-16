@props(['report', 'isFavorite' => false])

<div
    class="group relative rounded-lg border border-border bg-card transition hover:border-border hover:shadow-sm"
    wire:key="report-{{ $report['key'] }}"
    data-test="report-card-{{ $report['key'] }}"
>
    <a href="{{ $report['url'] }}" wire:navigate class="flex items-start gap-3 p-4 pr-11">
        <flux:icon :name="$report['icon']" class="mt-0.5 size-6 shrink-0 text-muted-foreground" />
        <div class="min-w-0">
            <div class="font-medium text-foreground">{{ __($report['label']) }}</div>
            <p class="mt-0.5 text-sm text-muted-foreground">{{ __($report['description']) }}</p>
        </div>
    </a>

    <button
        type="button"
        wire:click="toggleFavorite('{{ $report['key'] }}')"
        class="absolute right-2 top-2 rounded-md p-1.5 text-muted-foreground transition hover:bg-muted"
        aria-label="{{ $isFavorite ? __('Remove from favorites') : __('Add to favorites') }}"
        data-test="report-favorite-toggle-{{ $report['key'] }}"
    >
        @if ($isFavorite)
            <flux:icon.star variant="solid" class="size-5 text-amber-400" />
        @else
            <flux:icon.star variant="outline" class="size-5" />
        @endif
    </button>
</div>
