@extends('pdf.reports._layout', [
    'title' => $title ?? 'Transactions',
    'period' => $period,
    'metaLines' => array_filter([$context ?? null]),
])

@section('content')
@php
    $totalDebit = collect($rows)->sum('debit');
    $totalCredit = collect($rows)->sum('credit');
@endphp
<table class="data">
    <thead>
        <tr>
            <th>Date</th>
            <th>Entry #</th>
            <th>Account</th>
            <th>Name</th>
            <th>Memo</th>
            <th class="num">Debit</th>
            <th class="num">Credit</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row['date'] }}</td>
                <td>{{ $row['entry_no'] }}</td>
                <td>{{ $row['account'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['memo'] }}</td>
                <td class="num">{{ $row['debit'] ? number_format($row['debit'] / 100, 2) : '' }}</td>
                <td class="num">{{ $row['credit'] ? number_format($row['credit'] / 100, 2) : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center; color:#6b7280;">No transactions match these filters.</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" style="text-align:right;">Totals</td>
            <td class="num">{{ number_format($totalDebit / 100, 2) }}</td>
            <td class="num">{{ number_format($totalCredit / 100, 2) }}</td>
        </tr>
    </tfoot>
</table>
@endsection
