@extends('pdf.reports._layout', [
    'title' => 'General Ledger',
    'period' => $startDate.' to '.$endDate,
    'metaLines' => [$account->code.' — '.$account->name],
])

@section('content')
<table class="data">
    <thead>
        <tr>
            <th>Date</th>
            <th>Entry #</th>
            <th>Memo</th>
            <th class="num">Debit</th>
            <th class="num">Credit</th>
            <th class="num">Running</th>
        </tr>
    </thead>
    <tbody>
        <tr class="italic">
            <td colspan="5">Opening balance</td>
            <td class="num">{{ number_format($report['opening'] / 100, 2) }}</td>
        </tr>
        @forelse ($report['lines'] as $line)
            <tr>
                <td>{{ $line['date'] }}</td>
                <td>{{ $line['entry_no'] }}</td>
                <td>{{ $line['memo'] }}</td>
                <td class="num">{{ $line['debit'] ? number_format($line['debit'] / 100, 2) : '' }}</td>
                <td class="num">{{ $line['credit'] ? number_format($line['credit'] / 100, 2) : '' }}</td>
                <td class="num">{{ number_format($line['running'] / 100, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align:center; color:#6b7280;">No activity in this range.</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" style="text-align:right;">Closing balance</td>
            <td class="num">{{ number_format($report['closing'] / 100, 2) }}</td>
        </tr>
    </tfoot>
</table>
@endsection
