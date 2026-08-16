@extends('pdf.reports._layout', [
    'title' => $title ?? 'Income Statement',
    'period' => $startDate.' to '.$endDate.($comparisonNote ?? ''),
])

@section('content')
@php
    $fmt ??= new \App\Support\Reporting\ReportNumberFormat;
    $labels ??= \App\Support\Reporting\StatementLabels::for($company);
    $cols = $showComparison ? 3 : 2;
@endphp

<table class="data">
    <thead>
        <tr>
            <th>Account</th>
            <th class="num">Current</th>
            @if ($showComparison) <th class="num">Prior</th> @endif
        </tr>
    </thead>
    <tbody>
        @php $sections = [
            'income' => 'Income',
            'cogs' => 'Cost of Goods Sold',
            'expense' => 'Expenses',
        ]; @endphp

        @foreach ($sections as $key => $label)
            @php
                $blocks = $report[$key];
                $total = $report["total_{$key}"];
                $priorTotal = $report["prior_total_{$key}"];
            @endphp

            @if (! empty($blocks))
                <tr class="section"><td colspan="{{ $cols }}">{{ $label }}</td></tr>
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
                    <td>Total {{ $label }}</td>
                    <td class="num {{ $fmt->pdfClass($total) }}">{{ $fmt->format($total) }}</td>
                    @if ($showComparison)
                        <td class="num" style="color:#6b7280;">{{ $fmt->format($priorTotal) }}</td>
                    @endif
                </tr>

                @if ($key === 'cogs')
                    <tr class="subtotal">
                        <td>{{ $labels->grossProfit() }}</td>
                        <td class="num {{ $fmt->pdfClass($report['gross_profit']) }}">{{ $fmt->format($report['gross_profit']) }}</td>
                        @if ($showComparison)
                            <td class="num" style="color:#6b7280;">{{ $fmt->format($report['prior_gross_profit']) }}</td>
                        @endif
                    </tr>
                @endif
            @endif
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td>{{ $labels->netIncome() }}</td>
            <td class="num {{ $fmt->pdfClass($report['net_income']) }}">{{ $fmt->format($report['net_income']) }}</td>
            @if ($showComparison)
                <td class="num" style="color:#6b7280;">{{ $fmt->format($report['prior_net_income']) }}</td>
            @endif
        </tr>
    </tfoot>
</table>
@endsection
