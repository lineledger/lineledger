@extends('pdf.reports._layout', [
    'title' => $title ?? 'GIFI Statement',
    'period' => $startDate.' to '.$endDate,
])

@section('content')
<h2 style="font-size:13px; margin:0 0 6px;">Schedule 100 — Balance Sheet</h2>
<table class="data">
    <thead>
        <tr>
            <th>GIFI</th>
            <th>Description</th>
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($report['bs']['halves'] as $halfKey => $half)
            @if ($half['sections'] !== [] || ($halfKey === 'equity' && $half['net_income'] !== 0))
                <tr><td colspan="3" style="font-weight:bold; background:#f3f4f6;">{{ strtoupper($half['label']) }}</td></tr>
                @foreach ($half['sections'] as $section)
                    <tr><td colspan="3" style="color:#6b7280;">{{ $section['label'] }}</td></tr>
                    @foreach ($section['lines'] as $line)
                        <tr>
                            <td>{{ $line['code'] }}</td>
                            <td>{{ $line['label'] }}</td>
                            <td class="num">{{ number_format($line['amount'] / 100, 2) }}</td>
                        </tr>
                    @endforeach
                @endforeach
                @if ($halfKey === 'equity' && $half['net_income'] !== 0)
                    <tr><td></td><td><em>Net income for the year</em></td><td class="num">{{ number_format($half['net_income'] / 100, 2) }}</td></tr>
                @endif
                <tr><td></td><td style="text-align:right; font-weight:bold;">Total {{ $half['label'] }}</td><td class="num" style="font-weight:bold;">{{ number_format($half['total'] / 100, 2) }}</td></tr>
            @endif
        @endforeach
    </tbody>
    <tfoot>
        <tr><td></td><td style="text-align:right;">Total assets</td><td class="num">{{ number_format($report['bs']['total_assets'] / 100, 2) }}</td></tr>
        <tr><td></td><td style="text-align:right;">Total liabilities &amp; equity</td><td class="num">{{ number_format($report['bs']['total_le'] / 100, 2) }}</td></tr>
    </tfoot>
</table>
@unless ($report['bs']['balanced'])
    <div class="footer neg">Out of balance by {{ number_format(abs($report['bs']['total_assets'] - $report['bs']['total_le']) / 100, 2) }}.</div>
@endunless

<h2 style="font-size:13px; margin:16px 0 6px;">Schedule 125 — Income Statement</h2>
<table class="data">
    <thead>
        <tr>
            <th>GIFI</th>
            <th>Description</th>
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($report['is']['halves'] as $half)
            @if ($half['sections'] !== [])
                <tr><td colspan="3" style="font-weight:bold; background:#f3f4f6;">{{ strtoupper($half['label']) }}</td></tr>
                @foreach ($half['sections'] as $section)
                    @foreach ($section['lines'] as $line)
                        <tr>
                            <td>{{ $line['code'] }}</td>
                            <td>{{ $line['label'] }}</td>
                            <td class="num">{{ number_format($line['amount'] / 100, 2) }}</td>
                        </tr>
                    @endforeach
                @endforeach
                <tr><td></td><td style="text-align:right; font-weight:bold;">Total {{ $half['label'] }}</td><td class="num" style="font-weight:bold;">{{ number_format($half['total'] / 100, 2) }}</td></tr>
            @endif
        @endforeach
    </tbody>
    <tfoot>
        <tr><td></td><td style="text-align:right;">Net income (loss)</td><td class="num">{{ number_format($report['is']['net_income'] / 100, 2) }}</td></tr>
    </tfoot>
</table>

@if ($report['unassigned']['lines'] !== [])
    <h2 style="font-size:13px; margin:16px 0 6px;">Unassigned accounts</h2>
    <table class="data">
        <tbody>
            @foreach ($report['unassigned']['lines'] as $line)
                <tr>
                    <td>{{ $line['code'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td class="num">{{ number_format($line['amount'] / 100, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
@endsection
