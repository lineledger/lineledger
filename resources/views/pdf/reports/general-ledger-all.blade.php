@extends('pdf.reports._layout', [
    'title' => 'General Ledger',
    'period' => $startDate.' to '.$endDate,
    'metaLines' => [
        'All accounts, grouped by entry',
        $report['entry_count'].' entries · '.$report['line_count'].' lines',
    ],
])

@section('content')
<table class="data">
    <thead>
        <tr>
            <th>Date</th>
            <th>Entry #</th>
            <th>Code</th>
            <th>Account</th>
            <th>Memo</th>
            <th class="num">Debit</th>
            <th class="num">Credit</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($report['entries'] as $entry)
            <tr class="section">
                <td>{{ $entry['date'] }}</td>
                <td>{{ $entry['entry_no'] }}</td>
                <td colspan="3">{{ $entry['memo'] }}</td>
                <td></td>
                <td></td>
            </tr>
            @foreach ($entry['lines'] as $line)
                <tr>
                    <td></td>
                    <td></td>
                    <td>{{ $line['account_code'] }}</td>
                    <td>{{ $line['account_name'] }}</td>
                    <td>{{ $line['memo'] }}</td>
                    <td class="num">{{ $line['debit'] ? number_format($line['debit'] / 100, 2) : '' }}</td>
                    <td class="num">{{ $line['credit'] ? number_format($line['credit'] / 100, 2) : '' }}</td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td colspan="4"></td>
                <td style="text-align:right;">Entry total</td>
                <td class="num">{{ number_format($entry['total_debit'] / 100, 2) }}</td>
                <td class="num">{{ number_format($entry['total_credit'] / 100, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center; color:#6b7280;">No posted entries in this range.</td></tr>
        @endforelse
    </tbody>
    @if ($report['entry_count'] > 0)
        <tfoot>
            <tr>
                <td colspan="4"></td>
                <td style="text-align:right;">Grand total</td>
                <td class="num">{{ number_format($report['total_debit'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['total_credit'] / 100, 2) }}</td>
            </tr>
        </tfoot>
    @endif
</table>
@endsection
