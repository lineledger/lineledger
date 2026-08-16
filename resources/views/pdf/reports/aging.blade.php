@extends('pdf.reports._layout', [
    'title' => $title,
    'period' => 'as of '.$asOf,
])

@section('content')
<table class="data">
    <thead>
        <tr>
            <th>{{ $entityLabel }}</th>
            <th class="num">Current</th>
            <th class="num">1–30</th>
            <th class="num">31–60</th>
            <th class="num">61–90</th>
            <th class="num">90+</th>
            <th class="num">Total</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($report['rows'] as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ number_format($row['current'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['b1_30'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['b31_60'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['b61_90'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['b90_plus'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['total'] / 100, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center; color:#6b7280;">{{ $emptyMessage ?? 'No open documents as of this date.' }}</td></tr>
        @endforelse
    </tbody>
    @if (! empty($report['rows']))
        <tfoot>
            <tr>
                <td style="text-align:right;">Totals</td>
                <td class="num">{{ number_format($report['totals']['current'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['totals']['b1_30'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['totals']['b31_60'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['totals']['b61_90'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['totals']['b90_plus'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['totals']['total'] / 100, 2) }}</td>
            </tr>
        </tfoot>
    @endif
</table>
@endsection
