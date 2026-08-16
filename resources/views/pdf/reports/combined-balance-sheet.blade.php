@extends('pdf.reports._combined-layout', [
    'title' => 'Combined Balance Sheet',
    'period' => 'as of '.$asOf,
])

@section('content')
@php $labels ??= \App\Support\Reporting\StatementLabels::forType(null); @endphp
@foreach ([
    ['title' => 'Assets', 'key' => 'assets', 'total' => $report['total_assets']],
    ['title' => 'Liabilities', 'key' => 'liabilities', 'total' => $report['total_liabilities']],
    ['title' => $labels->equityShort(), 'key' => 'equity', 'total' => $report['total_equity']],
] as $sec)
    <table class="data" style="margin-bottom: 18px;">
        <thead>
            <tr><th colspan="2">{{ $sec['title'] }}</th></tr>
        </thead>
        <tbody>
            @forelse ($report[$sec['key']] as $bsGroup)
                <tr class="italic"><td colspan="2">{{ $bsGroup['label'] }}</td></tr>
                @foreach ($bsGroup['blocks'] as $block)
                    @if ($block['type'] === 'section')
                        <tr class="subsection"><td colspan="2" style="padding-left: 16px;">{{ $block['name'] }}</td></tr>
                    @endif
                    @foreach ($block['rows'] as $line)
                        <tr>
                            <td style="padding-left: {{ $block['type'] === 'section' ? '32px' : '16px' }};">{{ $line['name'] }}</td>
                            <td class="num">{{ number_format($line['balance'] / 100, 2) }}</td>
                        </tr>
                    @endforeach
                    @if ($block['type'] === 'section')
                        <tr class="subtotal">
                            <td style="text-align:right;">Total {{ $block['name'] }}</td>
                            <td class="num">{{ number_format($block['subtotal'] / 100, 2) }}</td>
                        </tr>
                    @endif
                @endforeach
                @if ($bsGroup['has_section'])
                    <tr class="subtotal">
                        <td style="text-align:right;">Total {{ $bsGroup['label'] }}</td>
                        <td class="num">{{ number_format($bsGroup['subtotal'] / 100, 2) }}</td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="2" style="color:#6b7280;">No accounts.</td></tr>
            @endforelse

            @if ($sec['key'] === 'equity' && ($report['retained_earnings_prior'] ?? 0) !== 0)
                <tr class="italic">
                    <td style="padding-left: 16px;">{{ $labels->retainedEarningsPriorRow() }}</td>
                    <td class="num">{{ number_format($report['retained_earnings_prior'] / 100, 2) }}</td>
                </tr>
            @endif

            @if ($sec['key'] === 'equity' && $report['net_income_ytd'] !== 0)
                <tr class="italic">
                    <td style="padding-left: 16px;">{{ $labels->netIncomeYtd() }}</td>
                    <td class="num">{{ number_format($report['net_income_ytd'] / 100, 2) }}</td>
                </tr>
            @endif
        </tbody>
        <tfoot>
            <tr>
                <td style="text-align:right;">Total {{ $sec['title'] }}</td>
                <td class="num">
                    @if ($sec['key'] === 'equity')
                        {{ number_format(($sec['total'] + ($report['retained_earnings_prior'] ?? 0) + $report['net_income_ytd']) / 100, 2) }}
                    @else
                        {{ number_format($sec['total'] / 100, 2) }}
                    @endif
                </td>
            </tr>
        </tfoot>
    </table>
@endforeach

<table class="data">
    <tr class="total">
        <td style="text-align:right; width: 70%;">{{ $labels->totalLiabilitiesAndEquity() }}</td>
        <td class="num">{{ number_format($report['total_le'] / 100, 2) }}</td>
    </tr>
</table>

@if ($report['total_assets'] !== $report['total_le'])
    <div class="footer neg">Out of balance — difference {{ number_format(abs($report['total_assets'] - $report['total_le']) / 100, 2) }}.</div>
@endif
@endsection
