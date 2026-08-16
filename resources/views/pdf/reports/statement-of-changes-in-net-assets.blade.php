@extends('pdf.reports._layout', [
    'title' => $title ?? 'Statement of Changes in Net Assets',
    'period' => $startDate.' to '.$endDate,
])

@section('content')
<table class="data">
    <thead>
        <tr>
            <th>Net asset class</th>
            <th class="num">Opening</th>
            <th class="num">Excess (deficiency)</th>
            <th class="num">Other changes</th>
            <th class="num">Closing</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($report['classes'] as $class)
            <tr>
                <td>{{ $class['label'] }}</td>
                <td class="num">{{ number_format($class['opening'] / 100, 2) }}</td>
                <td class="num">{{ number_format($class['excess'] / 100, 2) }}</td>
                <td class="num">{{ number_format($class['other'] / 100, 2) }}</td>
                <td class="num">{{ number_format($class['closing'] / 100, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total">
            <td>Total net assets</td>
            <td class="num">{{ number_format($report['total']['opening'] / 100, 2) }}</td>
            <td class="num">{{ number_format($report['total']['excess'] / 100, 2) }}</td>
            <td class="num">{{ number_format($report['total']['other'] / 100, 2) }}</td>
            <td class="num">{{ number_format($report['total']['closing'] / 100, 2) }}</td>
        </tr>
    </tfoot>
</table>
@endsection
