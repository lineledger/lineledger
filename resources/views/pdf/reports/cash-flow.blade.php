@extends('pdf.reports._layout', [
    'title' => $title ?? 'Cash Flow Statement',
    'period' => $startDate.' to '.$endDate.($comparisonNote ?? ''),
])

@section('content')
@php
    $fmt ??= new \App\Support\Reporting\ReportNumberFormat;
    $cols = $showComparison ? 3 : 2;
    $activities = [
        'operating' => 'Operating Activities',
        'investing' => 'Investing Activities',
        'financing' => 'Financing Activities',
    ];
@endphp

<table class="data">
    <thead>
        <tr>
            <th>Line</th>
            <th class="num">Current</th>
            @if ($showComparison) <th class="num">Prior</th> @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($activities as $key => $label)
            @php
                $blocks = $report[$key];
                $total = $report["total_{$key}"];
                $priorTotal = $report["prior_total_{$key}"];
            @endphp

            <tr class="section"><td colspan="{{ $cols }}">{{ $label }}</td></tr>

            @if ($key === 'operating')
                <tr>
                    <td style="padding-left: 16px;">Net income</td>
                    <td class="num {{ $fmt->pdfClass($report['net_income']) }}">{{ $fmt->format($report['net_income']) }}</td>
                    @if ($showComparison)
                        <td class="num" style="color:#6b7280;">{{ $fmt->format($report['prior_net_income']) }}</td>
                    @endif
                </tr>
            @endif

            @foreach ($blocks as $block)
                @if ($block['type'] === 'section')
                    <tr class="subsection"><td colspan="{{ $cols }}" style="padding-left: 16px;">{{ $block['name'] }}</td></tr>
                @endif
                @foreach ($block['rows'] as $a)
                    <tr>
                        <td style="padding-left: {{ $block['type'] === 'section' ? '32px' : '16px' }};">{{ $a['code'] }} — {{ $a['name'] }}</td>
                        <td class="num {{ $fmt->pdfClass($a['current']) }}">{{ $fmt->format($a['current']) }}</td>
                        @if ($showComparison)
                            <td class="num" style="color:#6b7280;">{{ $fmt->format($a['prior']) }}</td>
                        @endif
                    </tr>
                @endforeach
                @if ($block['type'] === 'section')
                    <tr class="subtotal">
                        <td style="padding-left: 24px;">Total {{ $block['name'] }}</td>
                        <td class="num">{{ $fmt->format($block['subtotal']) }}</td>
                        @if ($showComparison)
                            <td class="num" style="color:#6b7280;">{{ $fmt->format($block['prior_subtotal']) }}</td>
                        @endif
                    </tr>
                @endif
            @endforeach

            <tr class="subtotal">
                <td>Net cash from {{ $label }}</td>
                <td class="num {{ $fmt->pdfClass($total) }}">{{ $fmt->format($total) }}</td>
                @if ($showComparison)
                    <td class="num" style="color:#6b7280;">{{ $fmt->format($priorTotal) }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td>Net change in cash</td>
            <td class="num {{ $fmt->pdfClass($report['net_change']) }}">{{ $fmt->format($report['net_change']) }}</td>
            @if ($showComparison)
                <td class="num" style="color:#6b7280;">{{ $fmt->format($report['prior_net_change']) }}</td>
            @endif
        </tr>
        <tr>
            <td>Cash at beginning of period</td>
            <td class="num {{ $fmt->pdfClass($report['cash_beginning']) }}">{{ $fmt->format($report['cash_beginning']) }}</td>
            @if ($showComparison)
                <td class="num" style="color:#6b7280;">{{ $fmt->format($report['prior_cash_beginning']) }}</td>
            @endif
        </tr>
        <tr>
            <td>Cash at end of period</td>
            <td class="num {{ $fmt->pdfClass($report['cash_ending']) }}">{{ $fmt->format($report['cash_ending']) }}</td>
            @if ($showComparison)
                <td class="num" style="color:#6b7280;">{{ $fmt->format($report['prior_cash_ending']) }}</td>
            @endif
        </tr>
    </tfoot>
</table>
@endsection
