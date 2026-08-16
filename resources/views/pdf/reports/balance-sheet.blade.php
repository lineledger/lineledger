@extends('pdf.reports._layout', [
    'title' => $title ?? 'Balance Sheet',
    'period' => 'as of '.$asOf.($comparisonNote ?? ''),
])

@section('content')
@php
    $fmt ??= new \App\Support\Reporting\ReportNumberFormat;
    $labels ??= \App\Support\Reporting\StatementLabels::for($company);
    $showComparison = $showComparison ?? false;
    $cols = $showComparison ? 3 : 2;

    $section = function (string $key, string $title, array $groups, int $total, int $priorTotal) {
        return compact('key', 'title', 'groups', 'total', 'priorTotal');
    };
@endphp

@foreach ([
    $section('assets', 'Assets', $report['assets'], $report['total_assets'], $report['prior_total_assets'] ?? 0),
    $section('liabilities', 'Liabilities', $report['liabilities'], $report['total_liabilities'], $report['prior_total_liabilities'] ?? 0),
    $section('equity', $labels->equityHeading(), $report['equity'], $report['total_equity'], $report['prior_total_equity'] ?? 0),
] as $sec)
    <table class="data" style="margin-bottom: 18px;">
        <thead>
            <tr>
                <th>{{ $sec['title'] }}</th>
                @if ($showComparison)
                    <th class="num">Current</th>
                    <th class="num">Prior</th>
                @else
                    <th class="num"></th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($sec['groups'] as $group)
                <tr class="italic"><td colspan="{{ $cols }}">{{ $group['label'] }}</td></tr>
                @foreach ($group['blocks'] as $block)
                    @if ($block['type'] === 'section')
                        <tr class="subsection"><td colspan="{{ $cols }}" style="padding-left: 16px;">{{ $block['name'] }}</td></tr>
                    @endif
                    @foreach ($block['rows'] as $a)
                        <tr>
                            <td style="padding-left: {{ $block['type'] === 'section' ? '32px' : '16px' }};">{{ $a['code'] }} — {{ $a['name'] }}</td>
                            <td class="num {{ $fmt->pdfClass($a['balance']) }}">{{ $fmt->format($a['balance']) }}</td>
                            @if ($showComparison)
                                <td class="num" style="color:#6b7280;">{{ $fmt->format($a['prior']) }}</td>
                            @endif
                        </tr>
                    @endforeach
                    @if ($block['type'] === 'section')
                        <tr class="subtotal">
                            <td style="padding-left: 24px;">Total {{ $block['name'] }}</td>
                            <td class="num">{{ $fmt->format($block['subtotal']) }}</td>
                            @if ($showComparison)
                                <td class="num" style="color:#6b7280;">{{ $fmt->format($block['prior_subtotal']) }}</td>
                            @endif
                        </tr>
                    @endif
                @endforeach
            @empty
                <tr><td colspan="{{ $cols }}" style="color:#6b7280;">No accounts.</td></tr>
            @endforelse

            @if ($sec['key'] === 'equity' && ($report['net_income_ytd'] !== 0 || ($report['prior_net_income_ytd'] ?? 0) !== 0))
                <tr class="italic">
                    <td style="padding-left: 16px;">{{ $labels->netIncomeYtd() }}</td>
                    <td class="num">{{ $fmt->format($report['net_income_ytd']) }}</td>
                    @if ($showComparison)
                        <td class="num" style="color:#6b7280;">{{ $fmt->format($report['prior_net_income_ytd'] ?? 0) }}</td>
                    @endif
                </tr>
            @endif
        </tbody>
        <tfoot>
            <tr>
                <td style="text-align:right;">Total {{ $sec['title'] }}</td>
                <td class="num">
                    @if ($sec['key'] === 'equity')
                        {{ $fmt->format($sec['total'] + $report['net_income_ytd']) }}
                    @else
                        {{ $fmt->format($sec['total']) }}
                    @endif
                </td>
                @if ($showComparison)
                    <td class="num" style="color:#6b7280;">
                        @if ($sec['key'] === 'equity')
                            {{ $fmt->format($sec['priorTotal'] + ($report['prior_net_income_ytd'] ?? 0)) }}
                        @else
                            {{ $fmt->format($sec['priorTotal']) }}
                        @endif
                    </td>
                @endif
            </tr>
        </tfoot>
    </table>
@endforeach

<table class="data">
    <tr class="total">
        <td style="text-align:right; @unless($showComparison) width: 70%; @endunless">{{ $labels->totalLiabilitiesAndEquity() }}</td>
        <td class="num {{ $fmt->pdfClass($report['total_le']) }}">{{ $fmt->format($report['total_le']) }}</td>
        @if ($showComparison)
            <td class="num" style="color:#6b7280;">{{ $fmt->format($report['prior_total_le'] ?? 0) }}</td>
        @endif
    </tr>
</table>

@if ($report['total_assets'] !== $report['total_le'])
    <div class="footer neg">Balance sheet is out of balance — difference {{ $fmt->format(abs($report['total_assets'] - $report['total_le'])) }}.</div>
@endif
@endsection
