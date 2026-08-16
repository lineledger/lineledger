@extends('pdf.reports._layout', [
    'title' => $title,
    'period' => $startDate.' to '.$endDate,
    'metaLines' => [$contact->display_name],
])

@section('content')
<table class="data">
    <thead>
        <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Doc #</th>
            <th>Memo</th>
            <th class="num">Debit</th>
            <th class="num">Credit</th>
            <th class="num">Running</th>
        </tr>
    </thead>
    <tbody>
        <tr class="italic">
            <td colspan="6">Opening balance</td>
            <td class="num">{{ number_format($report['opening'] / 100, 2) }}</td>
        </tr>
        @forelse ($report['lines'] as $line)
            <tr>
                <td>{{ $line['date'] }}</td>
                <td>{{ $line['type'] }}</td>
                <td>{{ $line['doc_no'] }}</td>
                <td>{{ $line['memo'] }}</td>
                <td class="num">{{ $line['debit'] ? number_format($line['debit'] / 100, 2) : '' }}</td>
                <td class="num">{{ $line['credit'] ? number_format($line['credit'] / 100, 2) : '' }}</td>
                <td class="num">{{ number_format($line['running'] / 100, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center; color:#6b7280;">No transactions in this range.</td></tr>
        @endforelse
        @if (! empty($report['lines']))
            <tr class="subtotal">
                <td colspan="4" style="text-align:right;">Period totals</td>
                <td class="num">{{ number_format($report['period_debit'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['period_credit'] / 100, 2) }}</td>
                <td></td>
            </tr>
        @endif
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6" style="text-align:right;">Closing balance</td>
            <td class="num">{{ number_format($report['closing'] / 100, 2) }}</td>
        </tr>
    </tfoot>
</table>
@endsection
