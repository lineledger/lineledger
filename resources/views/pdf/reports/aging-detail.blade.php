@extends('pdf.reports._layout', [
    'title' => $title,
    'period' => 'as of '.$asOf,
])

@section('content')
@php
    $hasRows = ! empty($report['adjustments'])
        || collect($report['buckets'])->sum(fn ($bucket) => count($bucket['rows'])) > 0;
@endphp
<table class="data">
    <thead>
        <tr>
            <th>{{ $docLabel }} #</th>
            <th>{{ $entityLabel }}</th>
            <th>Date</th>
            <th>Due</th>
            <th class="num">Days overdue</th>
            <th class="num">Balance</th>
        </tr>
    </thead>
    <tbody>
        @if (! $hasRows)
            <tr><td colspan="6" style="text-align:center; color:#6b7280;">{{ $emptyMessage ?? 'No open documents as of this date.' }}</td></tr>
        @endif

        @foreach ($report['buckets'] as $bucket)
            @if (! empty($bucket['rows']))
                <tr>
                    <td colspan="6" style="background:#f3f4f6; font-weight:bold;">{{ $bucket['label'] }}</td>
                </tr>
                @foreach ($bucket['rows'] as $row)
                    <tr>
                        <td>{{ $row['doc_no'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['doc_date'] }}</td>
                        <td>{{ $row['due_date'] }}</td>
                        <td class="num">{{ $row['days_overdue'] }}</td>
                        <td class="num">{{ number_format($row['balance'] / 100, 2) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="5" style="text-align:right; font-style:italic; color:#6b7280;">Total {{ $bucket['label'] }}</td>
                    <td class="num" style="font-style:italic; color:#6b7280;">{{ number_format($bucket['subtotal'] / 100, 2) }}</td>
                </tr>
            @endif
        @endforeach

        @if (! empty($report['adjustments']))
            <tr>
                <td colspan="6" style="background:#f3f4f6; font-weight:bold;">Adjustments (credits, unapplied payments &amp; ledger entries)</td>
            </tr>
            @foreach ($report['adjustments'] as $adjustment)
                <tr>
                    <td>—</td>
                    <td colspan="4">{{ $adjustment['name'] }}</td>
                    <td class="num">{{ number_format($adjustment['amount'] / 100, 2) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="5" style="text-align:right; font-style:italic; color:#6b7280;">Total adjustments</td>
                <td class="num" style="font-style:italic; color:#6b7280;">{{ number_format($report['adjustments_total'] / 100, 2) }}</td>
            </tr>
        @endif
    </tbody>
    @if ($hasRows)
        <tfoot>
            <tr>
                <td colspan="5" style="text-align:right;">Grand total</td>
                <td class="num">{{ number_format($report['grand_total'] / 100, 2) }}</td>
            </tr>
        </tfoot>
    @endif
</table>
@endsection
