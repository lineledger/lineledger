@extends('pdf.reports._layout', [
    'title' => $title ?? __('T2125 Business Activities'),
    'period' => 'Tax year '.$taxYear,
])

@section('content')
<h2 style="font-size:13px; margin:0 0 6px;">{{ __('Parts 3 & 4 — Income and expenses') }}</h2>
<table class="data">
    <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Description') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
    <tbody>
        @foreach ($report['is']['halves'] as $half)
            @if ($half['sections'] !== [])
                <tr><td colspan="3" style="font-weight:bold; background:#f3f4f6;">{{ strtoupper(__($half['label'])) }}</td></tr>
                @foreach ($half['sections'] as $section)
                    @foreach ($section['lines'] as $line)
                        <tr><td>{{ $line['code'] }}</td><td>{{ __($line['label']) }}</td><td class="num">{{ number_format($line['amount'] / 100, 2) }}</td></tr>
                    @endforeach
                @endforeach
                <tr><td></td><td style="text-align:right; font-weight:bold;">{{ __('Total') }} {{ __($half['label']) }}</td><td class="num" style="font-weight:bold;">{{ number_format($half['total'] / 100, 2) }}</td></tr>
            @endif
        @endforeach
    </tbody>
    <tfoot>
        <tr><td></td><td style="text-align:right;">{{ __('Net income before CCA') }}</td><td class="num">{{ number_format($report['is']['net_income'] / 100, 2) }}</td></tr>
    </tfoot>
</table>

<h2 style="font-size:13px; margin:16px 0 6px;">{{ __('Part 7 — Capital cost allowance (Area A)') }}</h2>
<table class="data">
    <thead><tr><th>{{ __('Class') }}</th><th class="num">{{ __('Opening UCC') }}</th><th class="num">{{ __('Additions') }}</th><th class="num">{{ __('CCA') }}</th><th class="num">{{ __('Closing UCC') }}</th></tr></thead>
    <tbody>
        @forelse ($cca['rows'] as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="num">{{ number_format($row['opening_cents'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['additions_cents'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['cca_cents'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['closing_cents'] / 100, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center; color:#6b7280;">{{ __('No CCA classes with activity.') }}</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr><td colspan="3" style="text-align:right;">{{ __('Total CCA') }}</td><td class="num">{{ number_format($cca['total_cca_cents'] / 100, 2) }}</td><td></td></tr>
        <tr><td colspan="3" style="text-align:right; font-weight:bold;">{{ __('Net income after CCA') }}</td><td class="num" style="font-weight:bold;">{{ number_format($netAfterCca / 100, 2) }}</td><td></td></tr>
    </tfoot>
</table>

<h2 style="font-size:13px; margin:16px 0 6px;">{{ __('Part 5 — Balance sheet') }}</h2>
<table class="data">
    <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Description') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
    <tbody>
        @foreach ($report['bs']['halves'] as $halfKey => $half)
            @if ($half['sections'] !== [] || ($halfKey === 'equity' && $half['net_income'] !== 0))
                <tr><td colspan="3" style="font-weight:bold; background:#f3f4f6;">{{ strtoupper(__($half['label'])) }}</td></tr>
                @foreach ($half['sections'] as $section)
                    @foreach ($section['lines'] as $line)
                        <tr><td>{{ $line['code'] }}</td><td>{{ __($line['label']) }}</td><td class="num">{{ number_format($line['amount'] / 100, 2) }}</td></tr>
                    @endforeach
                @endforeach
                <tr><td></td><td style="text-align:right; font-weight:bold;">{{ __('Total') }} {{ __($half['label']) }}</td><td class="num" style="font-weight:bold;">{{ number_format($half['total'] / 100, 2) }}</td></tr>
            @endif
        @endforeach
    </tbody>
</table>
@endsection
