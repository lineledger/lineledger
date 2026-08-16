@extends('pdf.reports._layout', [
    'title' => $title ?? 'Trial Balance',
    'period' => 'as of '.$asOf,
])

@section('content')
@php $fmt ??= new \App\Support\Reporting\ReportNumberFormat; @endphp
<table class="data">
    <thead>
        <tr>
            <th>Code</th>
            <th>Account</th>
            <th>Type</th>
            <th class="num">Debit</th>
            <th class="num">Credit</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($report['rows'] as $row)
            <tr>
                <td>{{ $row['code'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['type'] }}</td>
                <td class="num">{{ $row['debit'] ? $fmt->format($row['debit']) : '' }}</td>
                <td class="num">{{ $row['credit'] ? $fmt->format($row['credit']) : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center; color:#6b7280;">No activity through this date.</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3" style="text-align:right;">Totals</td>
            <td class="num">{{ $fmt->format($report['totals']['debit']) }}</td>
            <td class="num">{{ $fmt->format($report['totals']['credit']) }}</td>
        </tr>
    </tfoot>
</table>

@if ($report['totals']['debit'] !== $report['totals']['credit'])
    <div class="footer neg">Trial balance is out of balance — this should never happen.</div>
@endif
@endsection
