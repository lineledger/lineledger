@extends('pdf.statutory._layout')

@section('content')
@php
    use App\Support\Statutory\StatutoryFormat as F;
    $blocks = array_values(array_filter([$r['soce']['prior'], $r['soce']['current']]));
    $withOther = collect($blocks)->contains(fn ($b) => $b['has_other_reserves']);
@endphp

@foreach ($blocks as $i => $b)
    @php
        $total = fn (array $x) => $x['share_capital'] + $x['other_reserves'] + $x['retained_earnings'];
        $rows = array_filter([
            [__('Profit after taxation'), 0, 0, $b['profit']],
            $b['dividends'] !== 0 ? [__('Dividends'), 0, 0, -$b['dividends']] : null,
            $b['shares_issued'] !== 0 ? [__('Shares issued'), $b['shares_issued'], 0, 0] : null,
            $b['other_reserve_movement'] !== 0 ? [__('Movement in other reserves'), 0, $b['other_reserve_movement'], 0] : null,
            $b['other_movements'] !== 0 ? [__('Other movements'), 0, 0, $b['other_movements']] : null,
        ]);
        $last = $i === count($blocks) - 1;
    @endphp
    <table class="fs" style="margin-top: {{ $i === 0 ? '6pt' : '26pt' }};">
        <tr>
            <td></td>
            <th class="num">{{ __('Share') }}<br>{{ __('Capital') }}<br>£</th>
            @if ($withOther)<th class="num">{{ __('Other') }}<br>{{ __('Reserves') }}<br>£</th>@endif
            <th class="num">{{ __('Retained') }}<br>{{ __('Earnings') }}<br>£</th>
            <th class="num">{{ __('Total') }}<br>{{ __('Equity') }}<br>£</th>
        </tr>
        <tr class="gap">
            <td>{{ __('At') }} {!! F::dateHtml($b['opening_date']) !!}</td>
            <td class="num">{{ F::amount($b['opening']['share_capital']) }}</td>
            @if ($withOther)<td class="num">{{ F::amount($b['opening']['other_reserves']) }}</td>@endif
            <td class="num">{{ F::amount($b['opening']['retained_earnings']) }}</td>
            <td class="num">{{ F::amount($total($b['opening'])) }}</td>
        </tr>
        @foreach ($rows as [$label, $sc, $or, $re])
            <tr>
                <td>{{ $label }}</td>
                <td class="num">{{ F::amount($sc) }}</td>
                @if ($withOther)<td class="num">{{ F::amount($or) }}</td>@endif
                <td class="num">{{ F::amount($re) }}</td>
                <td class="num">{{ F::amount($sc + $or + $re) }}</td>
            </tr>
        @endforeach
        <tr class="gap {{ $last ? 'total' : '' }}">
            <td>{{ __('At') }} {!! F::dateHtml($b['closing_date']) !!}</td>
            <td class="num line-top double">{{ F::amount($b['closing']['share_capital']) }}</td>
            @if ($withOther)<td class="num line-top double">{{ F::amount($b['closing']['other_reserves']) }}</td>@endif
            <td class="num line-top double">{{ F::amount($b['closing']['retained_earnings']) }}</td>
            <td class="num line-top double">{{ F::amount($total($b['closing'])) }}</td>
        </tr>
    </table>
@endforeach

@if ($notesRange !== '')
    <div class="footnote"><p>{{ __('The notes on :pages form part of these financial statements.', ['pages' => $notesRange]) }}</p></div>
@endif
@endsection
