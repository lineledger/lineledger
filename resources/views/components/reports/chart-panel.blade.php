@props([
    'charts' => [],
    'title' => '',
    'period' => '',
    'heading' => null,
    'collapsible' => true,
    'open' => false,
])

{{--
    Renders a report's chart series with a type switcher and PNG / PDF / Print
    export. Used by the three statement pages and the dashboard.

    - The host Livewire component supplies $charts (App\Support\Reporting\
      ReportChartBuilder output) and must `use App\Concerns\HasReportChart` for
      the exportChartPdf() action the PDF button calls.
    - One persistent Alpine `chartPanel` scope owns selection + open state + the
      Chart instance. The whole interactive region is wire:ignore so Livewire
      morphs never touch it (the switcher is rendered client-side with x-for, the
      canvas keeps its Chart instance). Fresh series flow in ONLY through the
      wire:key'd "feeder" at the bottom (outside the ignored region), whose
      x-init re-runs setCharts() whenever the data hash changes. This keeps
      selection/open intact across date-range and comparison changes.
    - The scope's x-data carries NO volatile data (no series, period, or title) —
      only static config. If volatile data were embedded here, every date-range
      change would alter the x-data attribute string, and Livewire's morph would
      re-initialize the Alpine component (resetting open/selection and destroying
      the Chart). All volatile data arrives through the feeder instead.
--}}

@php
    $charts = $charts ?: [];
    $heading = $heading ?? __('Charts');
    $openInit = $collapsible ? ($open ? 'true' : 'false') : 'true';
    $keySlug = \Illuminate\Support\Str::slug($title ?: $heading) ?: 'chart';
    // Re-key the feeder whenever ANY volatile input changes so its x-init re-runs
    // setCharts(). 'empty' keeps the key stable when there is nothing to chart.
    $feedHash = $charts ? substr(md5(json_encode([$charts, $period, $title])), 0, 12) : 'empty';
@endphp

<div
    {{ $attributes->class('rounded-xl border border-border') }}
    wire:key="chart-panel-{{ $keySlug }}"
    x-data="chartPanel({
        open: {{ $openInit }},
        labels: { hide: @js(__('Hide')), show: @js(__('Show')) },
    })"
    data-test="chart-panel"
>
    {{-- Everything interactive is wire:ignore so Livewire never morphs it. --}}
    <div wire:ignore>
        <div class="flex items-center justify-between gap-3 px-5 py-4">
            <div class="flex items-center gap-2">
                <flux:icon name="chart-bar" class="size-5 shrink-0 text-muted-foreground" />
                <div>
                    <p class="font-medium">{{ $heading }}</p>
                    <p x-show="period" x-text="period" class="text-xs text-muted-foreground"></p>
                </div>
            </div>
            @if ($collapsible)
                <flux:button size="sm" variant="ghost" x-on:click="open = ! open" data-test="chart-toggle">
                    <span x-text="open ? labels.hide : labels.show"></span>
                </flux:button>
            @endif
        </div>

        <div x-show="open" x-cloak class="border-t border-border px-5 pb-5 pt-4">
            <p x-show="! hasCharts()" class="py-6 text-center text-sm text-muted-foreground">{{ __('No data to chart for this period.') }}</p>

            <div x-show="manyCharts()" class="mb-4 flex flex-wrap gap-1" role="tablist">
                <template x-for="(cfg, key) in charts" :key="key">
                    <button
                        type="button"
                        x-on:click="select(key)"
                        x-bind:class="isActive(key) ? 'bg-secondary text-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                        x-bind:data-test="'chart-switch-' + key"
                        x-text="cfg.title"
                    ></button>
                </template>
            </div>

            <div x-show="hasCharts()" class="relative h-72 sm:h-80">
                <canvas x-ref="canvas"></canvas>
            </div>

            <div x-show="hasCharts()" class="mt-4 flex flex-wrap justify-end gap-2">
                <flux:button size="sm" variant="ghost" icon="photo" x-on:click="downloadPng()" data-test="chart-png">{{ __('PNG') }}</flux:button>
                <flux:button size="sm" variant="ghost" icon="document-arrow-down" x-on:click="exportPdf()" data-test="chart-pdf">{{ __('PDF') }}</flux:button>
                <flux:button size="sm" variant="ghost" icon="printer" x-on:click="printChart()" data-test="chart-print">{{ __('Print') }}</flux:button>
            </div>
        </div>
    </div>

    {{-- Feeder: outside the ignored region, recreated whenever the data hash
         changes, pushing fresh series into the persistent chartPanel above. --}}
    <div wire:key="chart-feed-{{ $keySlug }}-{{ $feedHash }}" x-init="setCharts(@js($charts), @js($period), @js($title))" class="hidden"></div>
</div>
