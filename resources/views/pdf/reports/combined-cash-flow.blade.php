@extends('pdf.reports._combined-layout', [
    'title' => 'Combined Cash Flow Statement',
    'period' => $startDate.' to '.$endDate,
])

@section('content')
@php
    $activities = [
        'operating' => 'Operating Activities',
        'investing' => 'Investing Activities',
        'financing' => 'Financing Activities',
    ];
@endphp

<table class="data">
    <thead>
        <tr><th>Line</th><th class="num">Amount</th></tr>
    </thead>
    <tbody>
        @foreach ($activities as $key => $label)
            <tr class="section"><td colspan="2">{{ $label }}</td></tr>

            @if ($key === 'operating')
                <tr>
                    <td style="padding-left: 16px;">Net income</td>
                    <td class="num">{{ number_format($report['net_income'] / 100, 2) }}</td>
                </tr>
            @endif

            @foreach ($report[$key] as $block)
                @if ($block['type'] === 'section')
                    <tr class="subsection"><td colspan="2" style="padding-left: 16px;">{{ $block['name'] }}</td></tr>
                @endif
                @foreach ($block['rows'] as $line)
                    <tr>
                        <td style="padding-left: {{ $block['type'] === 'section' ? '32px' : '16px' }};">{{ $line['name'] }}</td>
                        <td class="num {{ $line['current'] < 0 ? 'neg' : '' }}">{{ number_format($line['current'] / 100, 2) }}</td>
                    </tr>
                @endforeach
                @if ($block['type'] === 'section')
                    <tr class="subtotal">
                        <td style="text-align:right;">Total {{ $block['name'] }}</td>
                        <td class="num">{{ number_format($block['subtotal'] / 100, 2) }}</td>
                    </tr>
                @endif
            @endforeach

            <tr class="subtotal">
                <td style="text-align:right;">Net cash from {{ $label }}</td>
                <td class="num">{{ number_format($report['total_'.$key] / 100, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total">
            <td style="text-align:right;">Net change in cash</td>
            <td class="num {{ $report['net_change'] < 0 ? 'neg' : '' }}">{{ number_format($report['net_change'] / 100, 2) }}</td>
        </tr>
        <tr>
            <td style="text-align:right;">Cash at beginning of period</td>
            <td class="num">{{ number_format($report['cash_beginning'] / 100, 2) }}</td>
        </tr>
        <tr>
            <td style="text-align:right;">Cash at end of period</td>
            <td class="num">{{ number_format($report['cash_ending'] / 100, 2) }}</td>
        </tr>
    </tfoot>
</table>
@endsection
