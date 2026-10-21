@extends('pdf.statutory._layout')

@section('content')
@php
    use App\Support\Statutory\StatutoryFormat as F;
    use App\Support\Statutory\StatutoryAccountsProfile as P;
    $c = $r['sfp']['current'];
    $p = $r['sfp']['prior'];
    $pv = fn (string $k) => $p[$k] ?? 0;
    $either = fn (string $k) => ($c[$k] ?? 0) !== 0 || $pv($k) !== 0;
    $cap = $r['captions'];
    $fixedLines = array_values(array_filter(['intangible_assets', 'tangible_assets', 'investments'], $either));
    $equityLines = array_values(array_filter(['share_premium', 'revaluation_reserve', 'other_reserves'], $either));
    $signatories = $profile->signatories !== [] ? $profile->signatories : collect($profile->directors)->pluck('name')->take(2)->all();
    $endText = F::date($r['end']);
@endphp

<table class="fs">
    <tr>
        <td></td><th class="note"></th><th class="num"></th>
        <th class="num cy">{{ $cy }}</th>
        @if ($hasPrior)<th class="num" style="font-weight:normal;">{{ $py }}</th>@endif
    </tr>
    <tr>
        <td></td>
        <th class="note" style="font-weight:normal;">{{ __('Note') }}</th>
        <th class="num" style="font-weight:normal;">£</th>
        <th class="num cy">£</th>
        @if ($hasPrior)<th class="num" style="font-weight:normal;">£</th>@endif
    </tr>

@if ($micro)
    @php
        $rows = [
            [__('Fixed assets'), 'fixed_total', false, ''],
            [__('Current assets'), 'current_total', false, ''],
            [__('Creditors: amounts falling due within one year'), 'creditors_within', true, ''],
            [__('Net current assets'), 'net_current_assets', false, 'line-top'],
            [__('Total assets less current liabilities'), 'total_assets_less_current_liabilities', false, 'line-top'],
            [__('Creditors: amounts falling due after more than one year'), 'creditors_after', true, ''],
            [__('Provisions for liabilities'), 'provisions', true, ''],
        ];
    @endphp
    @foreach ($rows as [$label, $key, $deduct, $class])
        @if (in_array($key, ['fixed_total', 'current_total', 'creditors_within', 'net_current_assets', 'total_assets_less_current_liabilities'], true) || $either($key))
            <tr class="gap {{ $class ? 'total' : '' }}">
                <td>{{ $label }}</td><td class="note"></td><td class="num"></td>
                <td class="num cy {{ $class }}">{{ $deduct ? F::deduction($c[$key]) : F::amount($c[$key]) }}</td>
                @if ($hasPrior)<td class="num {{ $class }}">{{ $deduct ? F::deduction($pv($key)) : F::amount($pv($key)) }}</td>@endif
            </tr>
        @endif
    @endforeach
    <tr class="gap total">
        <td>{{ __('Net assets') }}</td><td class="note"></td><td class="num"></td>
        <td class="num cy line-top double">{{ F::amount($c['net_assets']) }}</td>
        @if ($hasPrior)<td class="num line-top double">{{ F::amount($pv('net_assets')) }}</td>@endif
    </tr>
    <tr class="gap total">
        <td>{{ __('Capital and reserves') }}</td><td class="note"></td><td class="num"></td>
        <td class="num cy double">{{ F::amount($c['equity_total']) }}</td>
        @if ($hasPrior)<td class="num double">{{ F::amount($pv('equity_total')) }}</td>@endif
    </tr>
@else
    {{-- Fixed assets --}}
    @if ($fixedLines !== [])
        <tr class="head"><td>{{ __('Fixed assets') }}</td><td></td><td></td><td></td>@if ($hasPrior)<td></td>@endif</tr>
        @foreach ($fixedLines as $line)
            <tr>
                <td>{{ match ($line) { 'intangible_assets' => __('Intangible assets'), 'tangible_assets' => __('Tangible fixed assets'), default => $cap['investments'] } }}</td>
                <td class="note">{{ $line === 'tangible_assets' ? ($notes['tangible'] ?? '') : '' }}</td>
                <td class="num"></td>
                <td class="num cy">{{ F::amount($c[$line]) }}</td>
                @if ($hasPrior)<td class="num">{{ F::amount($pv($line)) }}</td>@endif
            </tr>
        @endforeach
        @if (count($fixedLines) > 1)
            <tr class="total">
                <td></td><td></td><td class="num"></td>
                <td class="num cy line-top">{{ F::amount($c['fixed_total']) }}</td>
                @if ($hasPrior)<td class="num line-top">{{ F::amount($pv('fixed_total')) }}</td>@endif
            </tr>
        @endif
    @endif

    {{-- Current assets --}}
    <tr class="head"><td>{{ __('Current assets') }}</td><td></td><td></td><td></td>@if ($hasPrior)<td></td>@endif</tr>
    @foreach (['stocks' => __('Stocks'), 'debtors' => __('Debtors'), 'cash' => $cap['cash']] as $line => $label)
        @if ($either($line))
            <tr>
                <td>{{ $label }}</td>
                <td class="note">{{ $line === 'debtors' ? ($notes['debtors'] ?? '') : '' }}</td>
                <td class="num cy">{{ F::amount($c[$line]) }}</td>
                <td class="num"></td>
                @if ($hasPrior)<td class="num">{{ F::amount($pv($line)) }}</td>@endif
            </tr>
        @endif
    @endforeach
    <tr class="total">
        <td></td><td></td>
        <td class="num cy line-top">{{ F::amount($c['current_total']) }}</td>
        <td class="num"></td>
        @if ($hasPrior)<td class="num line-top" style="font-weight:normal;">{{ F::amount($pv('current_total')) }}</td>@endif
    </tr>

    <tr class="gap">
        <td><strong>{{ __('Creditors:') }}</strong> {{ __('amounts falling due within one year') }}</td>
        <td class="note">{{ $notes['creditors_within'] ?? '' }}</td>
        <td class="num cy">{{ F::deduction($c['creditors_within']) }}</td>
        <td class="num"></td>
        @if ($hasPrior)<td class="num">{{ F::deduction($pv('creditors_within')) }}</td>@endif
    </tr>
    <tr class="gap total">
        <td>{{ __('Net current assets') }}</td><td></td>
        <td class="num line-top">&nbsp;</td>
        <td class="num cy">{{ F::amount($c['net_current_assets']) }}</td>
        @if ($hasPrior)<td class="num line-top" style="font-weight:normal;">{{ F::amount($pv('net_current_assets')) }}</td>@endif
    </tr>
    <tr class="gap total">
        <td>{{ __('Total assets less current liabilities') }}</td><td></td><td class="num"></td>
        <td class="num cy line-top">{{ F::amount($c['total_assets_less_current_liabilities']) }}</td>
        @if ($hasPrior)<td class="num line-top" style="font-weight:normal;">{{ F::amount($pv('total_assets_less_current_liabilities')) }}</td>@endif
    </tr>

    @if ($either('creditors_after'))
        <tr class="gap">
            <td><strong>{{ __('Creditors:') }}</strong> {{ __('amounts falling due after more than one year') }}</td>
            <td class="note">{{ $notes['creditors_after'] ?? '' }}</td><td class="num"></td>
            <td class="num cy">{{ F::deduction($c['creditors_after']) }}</td>
            @if ($hasPrior)<td class="num">{{ F::deduction($pv('creditors_after')) }}</td>@endif
        </tr>
    @endif
    @if ($either('provisions'))
        <tr class="gap">
            <td>{{ __('Provisions for liabilities') }}</td><td></td><td class="num"></td>
            <td class="num cy">{{ F::deduction($c['provisions']) }}</td>
            @if ($hasPrior)<td class="num">{{ F::deduction($pv('provisions')) }}</td>@endif
        </tr>
    @endif
    <tr class="gap total">
        <td>{{ __('Net assets') }}</td><td></td><td class="num"></td>
        <td class="num cy line-top double">{{ F::amount($c['net_assets']) }}</td>
        @if ($hasPrior)<td class="num line-top double" style="font-weight:normal;">{{ F::amount($pv('net_assets')) }}</td>@endif
    </tr>

    <tr class="head"><td style="padding-top: 12pt;">{{ __('Capital and Reserves') }}</td><td></td><td></td><td></td>@if ($hasPrior)<td></td>@endif</tr>
    <tr>
        <td>{{ __('Called up share capital') }}</td>
        <td class="note">{{ $notes['share_capital'] ?? '' }}</td><td class="num"></td>
        <td class="num cy">{{ F::amount($c['share_capital']) }}</td>
        @if ($hasPrior)<td class="num">{{ F::amount($pv('share_capital')) }}</td>@endif
    </tr>
    @foreach ($equityLines as $line)
        <tr>
            <td>{{ $cap[$line] }}</td><td></td><td class="num"></td>
            <td class="num cy">{{ F::amount($c[$line]) }}</td>
            @if ($hasPrior)<td class="num">{{ F::amount($pv($line)) }}</td>@endif
        </tr>
    @endforeach
    <tr>
        <td>{{ __('Retained earnings') }}</td><td></td><td class="num"></td>
        <td class="num cy">{{ F::amount($c['retained_earnings'], false) }}</td>
        @if ($hasPrior)<td class="num">{{ F::amount($pv('retained_earnings'), false) }}</td>@endif
    </tr>
    <tr class="gap total">
        <td>{{ __("Equity Shareholders' funds") }}</td><td></td><td class="num"></td>
        <td class="num cy line-top double">{{ F::amount($c['equity_total']) }}</td>
        @if ($hasPrior)<td class="num line-top double" style="font-weight:normal;">{{ F::amount($pv('equity_total')) }}</td>@endif
    </tr>
@endif
</table>

<div class="keep" style="margin-top: 22pt;">
    @if ($profile->regime === P::REGIME_UK_2006)
        <p>{{ __('For the year ending :date the company was entitled to exemption from audit under section 477 of the Companies Act 2006 relating to small companies.', ['date' => $endText]) }}</p>
        <p>{{ __('The members have not required the company to obtain an audit of its accounts for the year in question in accordance with section 476.') }}</p>
        <p>{{ __('The directors acknowledge their responsibilities for complying with the requirements of the Act with respect to accounting records and the preparation of accounts.') }}</p>
        <p>{{ $micro
            ? __('These accounts have been prepared in accordance with the micro-entity provisions.')
            : __('These accounts have been prepared in accordance with the provisions applicable to companies subject to the small companies regime.') }}</p>
    @elseif ($micro)
        <p>{{ __('These financial statements have been prepared in accordance with FRS 105 The Financial Reporting Standard applicable to the Micro-entities Regime.') }}</p>
    @endif

    <p style="font-weight: bold; width: 60%;">{{ __('The financial statements were approved by the board of directors on') }}
        @if ($profile->approvalDate) {!! F::dateHtml(\Carbon\CarbonImmutable::parse($profile->approvalDate)) !!} @endif
        {{ __('and signed on their behalf by:') }}</p>

    <table class="signatures">
        <tr>
            @foreach (array_slice($signatories, 0, 2) as $name)
                <td>
                    <div class="dots">.....................................................</div>
                    <strong>{{ $name }}</strong><br><strong>{{ __('Director') }}</strong>
                </td>
            @endforeach
        </tr>
    </table>
</div>

@if ($notesRange !== '')
    <div class="footnote"><p>{{ __('The notes on :pages form part of these financial statements.', ['pages' => $notesRange]) }}</p></div>
@endif
@endsection
