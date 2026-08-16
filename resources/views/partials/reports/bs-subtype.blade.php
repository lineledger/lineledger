{{--
    Renders one balance-sheet subtype group: its accounts, split into custom
    sections (sub-header + subtotal) plus an Unassigned remainder. A subtype-level
    total row is shown only when the subtype actually contains a section.

    Expects (inherited from the parent view): $group ['label','blocks'],
    $showComparison, $cmpCols, $changeCell, $pctCell, $changeClass, and
    optionally $fmt (ReportNumberFormat — defaults to minus/cents for pages
    without the number-format controls).
--}}
@php
    $fmt ??= new \App\Support\Reporting\ReportNumberFormat;
    $blocks = $group['blocks'];
    $hasSection = collect($blocks)->contains(fn ($b) => $b['type'] === 'section');
    $subtypeTotal = collect($blocks)->sum('subtotal');
    $subtypePriorTotal = collect($blocks)->sum('prior_subtotal');
    $colspan = $showComparison ? 5 : 2;
@endphp
<div class="mb-3">
    <div class="mb-1 text-xs uppercase tracking-wide text-muted-foreground">{{ $group['label'] }}</div>
    <table class="w-full text-sm {{ $showComparison ? 'table-fixed' : '' }}">@if ($showComparison){!! $cmpCols !!}@endif
        @foreach ($blocks as $block)
            @if ($block['type'] === 'section')
                <tr data-test="bs-section-header">
                    <td class="py-1 pl-3 font-medium text-muted-foreground" colspan="{{ $colspan }}">{{ $block['name'] }}</td>
                </tr>
            @endif
            @foreach ($block['rows'] as $a)
                <tr data-test="bs-row">
                    <td class="py-1 {{ $block['type'] === 'section' ? 'pl-6' : 'pl-3' }}">
                        @if (! empty($a['id']))
                            <a href="{{ route('reports.transactions', ['company' => $company->slug, 'account' => $a['id'], 'start' => '1900-01-01', 'end' => $asOf]) }}" wire:navigate class="hover:underline" data-test="drill-account">{{ $a['code'] }} — {{ $a['name'] }}</a>
                        @else
                            {{ $a['code'] }} — {{ $a['name'] }}
                        @endif
                    </td>
                    <td class="w-24 py-1 text-right font-mono {{ $fmt->cssClass($a['balance']) }}">{{ $fmt->format($a['balance']) }}</td>
                    @if ($showComparison)
                        <td class="w-24 py-1 text-right font-mono text-muted-foreground">{{ $fmt->format($a['prior']) }}</td>
                        <td class="w-24 py-1 text-right font-mono {{ $changeClass($a['balance'], $a['prior']) }}">{{ $changeCell($a['balance'], $a['prior']) }}</td>
                        <td class="w-16 py-1 text-right font-mono text-muted-foreground">{{ $pctCell($a['balance'], $a['prior']) }}</td>
                    @endif
                </tr>
            @endforeach
            @if ($block['type'] === 'section')
                <tr class="border-t border-border">
                    <td class="py-1 pl-6 text-xs italic text-muted-foreground">{{ __('Total') }} {{ $block['name'] }}</td>
                    <td class="w-24 py-1 text-right font-mono italic text-muted-foreground" data-test="bs-section-subtotal-{{ $block['id'] }}">{{ $fmt->format($block['subtotal']) }}</td>
                    @if ($showComparison)
                        <td class="w-24 py-1 text-right font-mono text-muted-foreground">{{ $fmt->format($block['prior_subtotal']) }}</td>
                        <td class="w-24 py-1 text-right font-mono {{ $changeClass($block['subtotal'], $block['prior_subtotal']) }}">{{ $changeCell($block['subtotal'], $block['prior_subtotal']) }}</td>
                        <td class="w-16 py-1 text-right font-mono text-muted-foreground">{{ $pctCell($block['subtotal'], $block['prior_subtotal']) }}</td>
                    @endif
                </tr>
            @endif
        @endforeach
        @if ($hasSection)
            <tr class="border-t border-border">
                <td class="py-1 pl-3 font-medium">{{ __('Total') }} {{ $group['label'] }}</td>
                <td class="w-24 py-1 text-right font-mono font-medium {{ $fmt->cssClass($subtypeTotal) }}">{{ $fmt->format($subtypeTotal) }}</td>
                @if ($showComparison)
                    <td class="w-24 py-1 text-right font-mono text-muted-foreground">{{ $fmt->format($subtypePriorTotal) }}</td>
                    <td class="w-24 py-1 text-right font-mono {{ $changeClass($subtypeTotal, $subtypePriorTotal) }}">{{ $changeCell($subtypeTotal, $subtypePriorTotal) }}</td>
                    <td class="w-16 py-1 text-right font-mono text-muted-foreground">{{ $pctCell($subtypeTotal, $subtypePriorTotal) }}</td>
                @endif
            </tr>
        @endif
    </table>
</div>
