@extends('pdf.statutory._layout')

@section('content')
@php
    use App\Support\Statutory\StatutoryFormat as F;
    $sci = $r['sci'];
    $div = $sci['current']['dividends'];
    $divPrior = $sci['prior']['dividends'] ?? 0;
    $endText = F::dateHtml($r['end']);
    $sciTitle = $micro ? __('profit and loss account') : __('statement of comprehensive income');
@endphp

<p>{{ __('The directors present their report and the unaudited financial statements for the year ended') }} {!! $endText !!}.</p>

<h3>{{ __("Statement of directors' responsibilities") }}</h3>
<p>{{ __('Company law requires the directors to prepare financial statements for each financial year, which give a true and fair view of the state of affairs of the company and of the profit or loss of the company for that period. In preparing those financial statements, the directors are required to:') }}</p>
<ul class="dash">
    <li>- &nbsp;{{ __('select suitable accounting policies and then apply them consistently;') }}</li>
    <li>- &nbsp;{{ __('make judgements and estimates that are reasonable and prudent;') }}</li>
    <li>- &nbsp;{{ __('state whether applicable UK Accounting Standards have been followed, subject to any material departures disclosed and explained in the financial statements; and') }}</li>
    <li>- &nbsp;{{ __('prepare the financial statements on the going concern basis unless it is inappropriate to presume that the company will continue in business.') }}</li>
</ul>
<p>{{ __("The directors are responsible for keeping adequate accounting records that are sufficient to show and explain the company's transactions and disclose with reasonable accuracy at any time the financial position of the company and to enable them to ensure that the financial statements comply with the :act. They are also responsible for safeguarding the assets of the company, and hence for taking reasonable steps for the prevention and detection of fraud and other irregularities.", ['act' => $profile->legislation()]) }}</p>

@if ($profile->principalActivity !== '')
    <h3>{{ __('Principal activity') }}</h3>
    <p>{{ $profile->principalActivity }}</p>
@endif

@if (isset($pages['income_statement']))
    <h3>{{ __('Results and dividends') }}</h3>
    <p>{{ __('The results of the company for the year are set out in the :statement on page :page.', ['statement' => $sciTitle, 'page' => $pages['income_statement']]) }}</p>
@else
    <h3>{{ __('Dividends') }}</h3>
@endif
@if ($div !== 0)
    <p>{{ __('The company paid a dividend of £:amount during the year', ['amount' => number_format($div)]) }}@if ($hasPrior) ({{ $py }} - £{{ number_format($divPrior) }})@endif.</p>
@else
    <p>{{ __('The company did not pay a dividend during the year') }}@if ($hasPrior && $divPrior !== 0) ({{ $py }} - £{{ number_format($divPrior) }})@endif.</p>
@endif

@if ($profile->directors !== [])
    <h3>{{ __('Directors') }}</h3>
    @php $hasShares = collect($profile->directors)->contains(fn ($d) => $d['shares_current'] !== '' || $d['shares_prior'] !== ''); @endphp
    <p>{{ $hasShares
        ? __('The directors who served during the year and their beneficial interests in the ordinary share capital were:')
        : __('The directors who served during the year were:') }}</p>
    @if ($hasShares)
        <table class="fs" style="width: 70%;">
            <tr><td></td><th colspan="2" style="text-align:center;">{{ __('Number of shares') }}</th></tr>
            <tr><td></td><th class="num cy" style="text-align:center;">{{ $cy }}</th><th class="num" style="text-align:center; font-weight: normal;">{{ $py }}</th></tr>
            @foreach ($profile->directors as $d)
                <tr>
                    <td>{{ $d['name'] }}</td>
                    <td class="num cy" style="text-align:center;">{{ $d['shares_current'] === '' || $d['shares_current'] === '0' ? '-' : $d['shares_current'] }}</td>
                    <td class="num" style="text-align:center;">{{ $d['shares_prior'] === '' || $d['shares_prior'] === '0' ? '-' : $d['shares_prior'] }}</td>
                </tr>
            @endforeach
        </table>
    @else
        @foreach ($profile->directors as $d)
            <p style="margin:0 0 2pt 0;">{{ $d['name'] }}</p>
        @endforeach
    @endif
@endif

@if ($profile->regime === \App\Support\Statutory\StatutoryAccountsProfile::REGIME_IOM_1931)
    <h3>{{ __('Auditors and Annual General Meeting (AGM)') }}</h3>
    <p>{{ __('The company has elected, by resolution, to dispense with the requirements to have its accounts audited and to hold AGMs. Members are hereby advised that they may, by notice in writing to the Company, rescind the aforesaid elections.') }}</p>
@elseif ($profile->regime === \App\Support\Statutory\StatutoryAccountsProfile::REGIME_IOM_2006)
    <h3>{{ __('Audit') }}</h3>
    <p>{{ __('The company is exempt from the requirement to have its accounts audited and the members have not required an audit.') }}</p>
@else
    <h3>{{ __('Small company provisions') }}</h3>
    <p>{{ __('This report has been prepared in accordance with the provisions applicable to companies entitled to the small companies exemption.') }}</p>
@endif

<p style="margin-top: 10pt;">{{ __('This report was approved by the board on') }}
    @if ($profile->approvalDate) {!! F::dateHtml(\Carbon\CarbonImmutable::parse($profile->approvalDate)) !!}. @else <span class="dots">..................................................</span> @endif
</p>

@php $signatory = $profile->directorsReportSignatory ?: ($profile->directors[0]['name'] ?? ''); @endphp
<div style="margin-top: 34pt;">
    <div class="dots">..............................................</div>
    <strong>{{ $signatory }}</strong><br>
    <strong>{{ $profile->directorsReportSignatoryRole }}</strong>
</div>
@endsection
