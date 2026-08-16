@extends('pdf.reports._layout', [
    'title' => $title ?? 'Open Bills',
    'period' => 'as of '.$asOf,
])

@section('content')
<table class="data">
    <thead>
        <tr>
            <th>Bill</th>
            <th>Vendor</th>
            <th>Date</th>
            <th>Due</th>
            <th class="num">Total</th>
            <th class="num">Paid</th>
            <th class="num">Balance</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($report['rows'] as $row)
            <tr>
                <td>{{ $row['bill_no'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['bill_date'] }}</td>
                <td>{{ $row['due_date'] ?? '—' }}{{ $row['days_overdue'] > 0 ? ' ('.$row['days_overdue'].'d)' : '' }}</td>
                <td class="num">{{ number_format($row['total'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['paid'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['balance'] / 100, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center; color:#6b7280;">No open bills as of this date.</td></tr>
        @endforelse
    </tbody>
    @if (! empty($report['rows']))
        <tfoot>
            <tr>
                <td colspan="4" style="text-align:right;">{{ $report['totals']['count'] }} open bills</td>
                <td class="num">{{ number_format($report['totals']['total'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['totals']['paid'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['totals']['balance'] / 100, 2) }}</td>
            </tr>
        </tfoot>
    @endif
</table>
@endsection
