@extends('pdf.reports._combined-layout', [
    'title' => 'Combined Income Statement',
    'period' => $startDate.' to '.$endDate,
])

@section('content')
@php $labels ??= \App\Support\Reporting\StatementLabels::forType(null); @endphp
<table class="data">
    <thead>
        <tr><th>Line</th><th class="num">Amount</th></tr>
    </thead>
    <tbody>
        @foreach (['income' => 'Income', 'cogs' => 'Cost of Goods Sold', 'expense' => 'Expenses'] as $key => $label)
            @if (! empty($report[$key]))
                <tr class="section"><td colspan="2">{{ $label }}</td></tr>
                @foreach ($report[$key] as $block)
                    @if ($block['type'] === 'section')
                        <tr class="subsection"><td colspan="2" style="padding-left: 16px;">{{ $block['name'] }}</td></tr>
                    @endif
                    @foreach ($block['rows'] as $line)
                        <tr>
                            <td style="padding-left: {{ $block['type'] === 'section' ? '32px' : '16px' }};">{{ $line['name'] }}</td>
                            <td class="num">{{ number_format($line['current'] / 100, 2) }}</td>
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
                    <td style="text-align:right;">Total {{ $label }}</td>
                    <td class="num">{{ number_format($report['total_'.$key] / 100, 2) }}</td>
                </tr>
                @if ($key === 'cogs')
                    <tr class="subtotal">
                        <td style="text-align:right;">{{ $labels->grossProfit() }}</td>
                        <td class="num">{{ number_format($report['gross_profit'] / 100, 2) }}</td>
                    </tr>
                @endif
            @endif
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total">
            <td style="text-align:right;">{{ $labels->netIncome() }}</td>
            <td class="num {{ $report['net_income'] < 0 ? 'neg' : '' }}">{{ number_format($report['net_income'] / 100, 2) }}</td>
        </tr>
    </tfoot>
</table>
@endsection
