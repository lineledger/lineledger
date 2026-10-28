@extends('pdf.statutory._layout')

@section('content')
@php
    use App\Support\Statutory\StatutoryFormat as F;
    $t = $r['tangible'];
    $classes = $t['classes'];
    $showTotal = count($classes) > 1;
    $officeOneLine = collect(preg_split('/\r?\n/', $profile->registeredOffice))->map(fn ($l) => trim($l))->filter()->implode(', ');
    $openDate = F::dateHtml($r['start']);
    $closeDate = F::dateHtml($r['end']);
    $priorEnd = F::dateHtml($r['prior_end']);
    $head = fn (string $key, string $title) => '<div class="notehead"><span class="no">'.$notes[$key].'.</span>'.$title.'</div>';
    $twoCol = function (array $rows, bool $total) use ($cy, $py, $hasPrior) {
        return view('pdf.statutory._analysis', compact('rows', 'total', 'cy', 'py', 'hasPrior'))->render();
    };
    $shareAmount = function (string $text): ?int {
        if (preg_match('/([\d,]+)\s+[^£]*£\s?([\d.]+)/u', $text, $m) !== 1) {
            return null;
        }
        return (int) round(((int) str_replace(',', '', $m[1])) * (float) $m[2]);
    };
    $employees = $profile->averageEmployees ?? count($profile->directors);
@endphp

@if ($micro)
    {!! $head('policies', __('Accounting policies')) !!}
    <div class="indent">
        <p>{{ __('These financial statements have been prepared in accordance with FRS 105 The Financial Reporting Standard applicable to the Micro-entities Regime and the :act.', ['act' => $profile->legislation()]) }}</p>
        <p>{{ __('The financial statements have been prepared on a historical cost basis. Income and expenditure are accounted for on an accruals basis.') }}</p>
    </div>

    {!! $head('employees', __('Employees')) !!}
    <div class="indent"><p>{{ __('The average number of persons employed by the company (including directors) during the year was :n.', ['n' => $employees]) }}</p></div>

    {!! $head('directors_advances', __('Advances, credits and guarantees granted to directors')) !!}
    <div class="indent"><p>{{ $profile->directorsAdvances !== '' ? $profile->directorsAdvances : __('There were no advances, credits or guarantees granted to the directors during the year.') }}</p></div>

    @isset($notes['guarantees'])
        {!! $head('guarantees', __('Guarantees and other financial commitments')) !!}
        <div class="indent"><p>{{ $profile->guarantees }}</p></div>
    @endisset
@else
    {!! $head('general', __('General Information')) !!}
    <div class="indent">
        <p>{{ __(':name is a company limited by shares, incorporated in :place.', ['name' => $company->legal_name ?: $company->name, 'place' => $profile->incorporatedIn ?: __('the United Kingdom')]) }}@if ($officeOneLine !== '') {{ __('Its registered office is :office.', ['office' => $officeOneLine]) }}@endif</p>
    </div>

    {!! $head('policies', __('Summary of significant accounting policies')) !!}
    <div class="indent">
        <div><strong>a)&nbsp; {{ __('Basis of preparation of Financial Statements') }}</strong></div>
        <p class="indent" style="margin-left: 6mm;">{{ __('The financial statements have been prepared in accordance with FRS 102 Section 1A Small Entities.') }}<br><br>{{ __('The financial statements have been prepared on a historical cost basis.') }}</p>

        <div><strong>b)&nbsp; {{ __('Income and expenditure') }}</strong></div>
        <p style="margin-left: 6mm;">{{ __('Income and expenditure are accounted for on an accruals basis.') }}</p>

        @if (isset($notes['tangible']) || $profile->depreciationRates !== [])
            <div><strong>c)&nbsp; {{ __('Depreciation of fixed assets and impairment') }}</strong></div>
            <div style="margin-left: 6mm;">
                <p style="margin-bottom: 2pt;">{{ __('Depreciation is calculated so as to write off the cost of an asset, less its estimated residual value, over the useful economic life of that asset as follows:') }}</p>
                @if ($profile->depreciationRates !== [])
                    <table style="margin: 0 0 3pt 10mm; border-collapse: collapse;">
                        @foreach ($profile->depreciationRates as $rate)
                            <tr><td style="width: 45mm;">{{ $rate['class'] }}</td><td style="width: 18mm;">{{ $rate['rate'] }}</td><td>{{ $rate['method'] }}</td></tr>
                        @endforeach
                    </table>
                @endif
                <p>{{ __('At each reporting date, goodwill and other fixed assets, including tangible fixed assets and investments, but excluding investment properties, are assessed to determine whether there is an indication that the carrying amount of an asset may be more than its recoverable amount and that the asset should be impaired. If there is an indication of possible impairment, the recoverable amount of an asset, which is the higher of its value in use and its net realisable value, is estimated and compared with its carrying amount. If the recoverable amount is lower, the carrying amount of the asset is written down to its estimated recoverable amount and an impairment loss is recognised.') }}</p>
            </div>
        @endif
    </div>

    @isset($notes['judgements'])
        {!! $head('judgements', __('Critical accounting estimates and judgements')) !!}
        <div class="indent"><p>{{ $profile->judgementsNote }}</p></div>
    @endisset

    @isset($notes['employees'])
        {!! $head('employees', __('Employees')) !!}
        <div class="indent"><p>{{ __('The average monthly number of employees, including directors, during the year was :n.', ['n' => $profile->averageEmployees]) }}</p></div>
    @endisset

    @isset($notes['taxation'])
        {!! $head('taxation', __('Taxation')) !!}
        <div class="indent"><p>{{ $profile->taxationNote !== '' ? $profile->taxationNote : __('The tax charge on the profit for the year was £:amount.', ['amount' => number_format($r['sci']['current']['taxation'])]) }}</p></div>
    @endisset

    @isset($notes['tangible'])
        <div class="keep">
        {!! $head('tangible', __('Tangible fixed assets')) !!}
        <table class="fs indent" style="width: auto; margin-left: 8mm;">
            <tr>
                <td style="width: 62mm;"></td>
                @foreach ($classes as $name => $cl)<th class="num" style="width: 26mm;">{!! nl2br(e(wordwrap($name, 14))) !!}<br>£</th>@endforeach
                @if ($showTotal)<th class="num" style="width: 22mm;">{{ __('Total') }}<br>£</th>@endif
            </tr>
            @php
                $row = function (string $label, string $key, string $class = '') use ($classes, $showTotal, $t) {
                    $cells = collect($classes)->map(fn ($cl) => '<td class="num '.$class.'">'.F::amount($cl[$key]).'</td>')->implode('');
                    $total = $showTotal ? '<td class="num '.$class.'">'.F::amount($t['total'][$key]).'</td>' : '';
                    return '<tr><td>'.$label.'</td>'.$cells.$total.'</tr>';
                };
                $eliminated = $t['total']['eliminated'] !== 0;
            @endphp
            <tr class="head"><td>{{ __('Cost') }}</td></tr>
            {!! $row(__('At').' '.$openDate, 'cost_open') !!}
            {!! $row(e(__('Additions')), 'additions') !!}
            {!! $row(e(__('Disposals')), 'disposals') !!}
            {!! $row(__('At').' '.$closeDate, 'cost_close', 'line-top') !!}
            <tr class="head"><td>{{ __('Depreciation') }}</td></tr>
            {!! $row(__('At').' '.$openDate, 'dep_open') !!}
            {!! $row(e(__('Charge for the year')), 'charge') !!}
            @if ($eliminated){!! $row(e(__('Eliminated on disposals')), 'eliminated') !!}@endif
            {!! $row(__('At').' '.$closeDate, 'dep_close', 'line-top') !!}
            <tr class="head"><td>{{ __('Net book value') }}</td></tr>
            {!! str_replace('<td class="num line-top double">', '<td class="num line-top double" style="font-weight:bold;">', $row(__('At').' '.$closeDate, 'nbv_close', 'line-top double')) !!}
            @if ($hasPrior)
                <tr class="head"><td>{{ __('Net book value') }}</td></tr>
                {!! $row(__('At').' '.$priorEnd, 'nbv_open', 'double') !!}
            @endif
        </table>
        </div>
    @endisset

    @isset($notes['debtors'])
        <div class="keep">
        {!! $head('debtors', __('Debtors')) !!}
        {!! $twoCol($r['analysis']['debtors'], count($r['analysis']['debtors']) > 1) !!}
        </div>
    @endisset

    @isset($notes['creditors_within'])
        <div class="keep">
        {!! $head('creditors_within', '<strong>'.e(__('Creditors:')).'</strong> <span style="font-weight:normal;">'.e(__('amounts falling due within one year')).'</span>') !!}
        {!! $twoCol($r['analysis']['creditors_within'], count($r['analysis']['creditors_within']) > 1) !!}
        </div>
    @endisset

    @isset($notes['creditors_after'])
        <div class="keep">
        {!! $head('creditors_after', '<strong>'.e(__('Creditors:')).'</strong> <span style="font-weight:normal;">'.e(__('amounts falling due after more than one year')).'</span>') !!}
        {!! $twoCol($r['analysis']['creditors_after'], count($r['analysis']['creditors_after']) > 1) !!}
        @if ($profile->creditorsAfterNarrative !== '')
            <p class="indent" style="margin-top: 8pt;">{{ $profile->creditorsAfterNarrative }}</p>
        @endif
        </div>
    @endisset

    @isset($notes['share_capital'])
        <div class="keep">
        {!! $head('share_capital', __('Share capital')) !!}
        <table class="fs" style="margin-left: 8mm; width: 92%;">
            <tr><td></td><th class="num cy">{{ $cy }}<br>£</th>@if ($hasPrior)<th class="num" style="font-weight:normal;">{{ $py }}<br>£</th>@endif</tr>
            @if ($profile->authorisedShares !== '')
                @php $auth = $shareAmount($profile->authorisedShares); @endphp
                <tr class="head"><td>{{ __('Authorised share capital') }}</td></tr>
                <tr><td>{{ $profile->authorisedShares }}</td>
                    <td class="num cy double">{{ $auth !== null ? F::amount($auth) : '' }}</td>
                    @if ($hasPrior)<td class="num double">{{ $auth !== null ? F::amount($auth) : '' }}</td>@endif</tr>
            @endif
            <tr class="head"><td>{{ __('Issued share capital') }}</td></tr>
            <tr><td>{{ $profile->issuedShares !== '' ? $profile->issuedShares : __('Called up, allotted and fully paid') }}</td>
                <td class="num cy double">{{ F::amount($r['sfp']['current']['share_capital']) }}</td>
                @if ($hasPrior)<td class="num double">{{ F::amount($r['sfp']['prior']['share_capital'] ?? 0) }}</td>@endif</tr>
        </table>
        </div>
    @endisset

    @isset($notes['directors_advances'])
        {!! $head('directors_advances', __('Advances, credits and guarantees granted to directors')) !!}
        <div class="indent"><p>{{ $profile->directorsAdvances }}</p></div>
    @endisset

    @isset($notes['related_parties'])
        {!! $head('related_parties', __('Related party transactions')) !!}
        <div class="indent"><p>{{ $profile->relatedParties }}</p></div>
    @endisset
@endif
@endsection
