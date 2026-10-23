@extends('pdf.statutory._layout')

@section('content')
@php
    use App\Support\Statutory\StatutoryFormat as F;
    $c = $r['sci']['current'];
    $p = $r['sci']['prior'];
    $pv = fn (string $k) => $p[$k] ?? 0;
    $cap = $r['captions'];
    $policy = isset($notes['policies']) ? $notes['policies'].'b)' : '';
    $showCos = $c['cost_of_sales'] !== 0 || $pv('cost_of_sales') !== 0;
    $showOther = $c['other_operating_income'] !== 0 || $pv('other_operating_income') !== 0;
@endphp

<table class="fs">
    <tr>
        <td></td>
        <th class="note"></th>
        <th class="num"></th>
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
            [__('Turnover'), 'turnover', false],
            [__('Other income'), null, false],
            [__('Cost of raw materials and consumables'), 'cost_of_sales', true],
            [__('Staff costs'), 'staff_costs', true],
            [__('Depreciation and other amounts written off assets'), 'depreciation', true],
            [__('Other charges'), 'other_charges', true],
            [__('Tax'), 'taxation', true],
        ];
        $other = fn ($x) => ($x['other_operating_income'] ?? 0) + ($x['interest_receivable'] ?? 0);
    @endphp
    @foreach ($rows as [$label, $key, $deduct])
        @php
            $cv = $key === null ? $other($c) : $c[$key];
            $pvv = $key === null ? ($p ? $other($p) : 0) : $pv($key);
        @endphp
        <tr class="gap">
            <td>{{ $label }}</td><td class="note"></td><td class="num"></td>
            <td class="num cy">{{ $deduct ? F::deduction($cv) : F::amount($cv) }}</td>
            @if ($hasPrior)<td class="num">{{ $deduct ? F::deduction($pvv) : F::amount($pvv) }}</td>@endif
        </tr>
    @endforeach
    <tr class="gap total">
        <td>{{ __('Profit for the financial year') }}</td><td class="note"></td><td class="num"></td>
        <td class="num cy line-top double">{{ F::amount($c['profit_after_tax']) }}</td>
        @if ($hasPrior)<td class="num line-top double">{{ F::amount($pv('profit_after_tax')) }}</td>@endif
    </tr>
@else
    <tr class="gap total">
        <td>{{ $cap['turnover'] }}</td>
        <td class="note" style="font-weight:normal;">{{ $policy }}</td>
        <td class="num"></td>
        <td class="num cy">{{ F::amount($c['turnover']) }}</td>
        @if ($hasPrior)<td class="num" style="font-weight:normal;">{{ F::amount($pv('turnover')) }}</td>@endif
    </tr>

    @if ($showCos)
        <tr>
            <td>{{ $cap['cost_of_sales'] }}</td><td class="note"></td><td class="num"></td>
            <td class="num cy">{{ F::deduction($c['cost_of_sales']) }}</td>
            @if ($hasPrior)<td class="num">{{ F::deduction($pv('cost_of_sales')) }}</td>@endif
        </tr>
        <tr class="total">
            <td>{{ __('Gross profit') }}</td><td class="note"></td><td class="num"></td>
            <td class="num cy line-top">{{ F::amount($c['gross_profit']) }}</td>
            @if ($hasPrior)<td class="num line-top" style="font-weight:normal;">{{ F::amount($pv('gross_profit')) }}</td>@endif
        </tr>
    @endif

    @if ($showOther)
        <tr>
            <td>{{ $cap['other_operating_income'] }}</td><td class="note"></td><td class="num"></td>
            <td class="num cy">{{ F::amount($c['other_operating_income']) }}</td>
            @if ($hasPrior)<td class="num">{{ F::amount($pv('other_operating_income')) }}</td>@endif
        </tr>
    @endif

    <tr class="gap total">
        <td>{{ __('Expenses') }}</td>
        <td class="note" style="font-weight:normal;">{{ $policy }}</td>
        <td class="num"></td><td class="num"></td>@if ($hasPrior)<td class="num"></td>@endif
    </tr>
    @foreach ($r['sci']['expense_rows'] as $row)
        <tr>
            <td>{{ $row['label'] }}</td><td class="note"></td>
            <td class="num cy">{{ F::amount($row['current']) }}</td>
            <td class="num"></td>
            @if ($hasPrior)<td class="num">{{ F::amount($row['prior']) }}</td>@endif
        </tr>
    @endforeach
    <tr>
        <td></td><td class="note"></td>
        <td class="num line-top" style="padding-top:0;">&nbsp;</td>
        <td class="num cy">{{ F::deduction($c['expense_total']) }}</td>
        @if ($hasPrior)<td class="num line-top">{{ F::deduction($pv('expense_total')) }}</td>@endif
    </tr>

    <tr class="gap total">
        <td>{{ __('Operating profit') }}</td><td class="note"></td><td class="num"></td>
        <td class="num cy line-top">{{ F::amount($c['operating_profit']) }}</td>
        @if ($hasPrior)<td class="num line-top" style="font-weight:normal;">{{ F::amount($pv('operating_profit')) }}</td>@endif
    </tr>
    @if ($c['interest_receivable'] !== 0 || $pv('interest_receivable') !== 0)
        <tr>
            <td>{{ $cap['interest_receivable'] }}</td><td class="note"></td><td class="num"></td>
            <td class="num cy">{{ F::amount($c['interest_receivable']) }}</td>
            @if ($hasPrior)<td class="num">{{ F::amount($pv('interest_receivable')) }}</td>@endif
        </tr>
    @endif
    @if ($c['interest_payable'] !== 0 || $pv('interest_payable') !== 0)
        <tr>
            <td>{{ $cap['interest_payable'] }}</td><td class="note"></td><td class="num"></td>
            <td class="num cy">{{ F::deduction($c['interest_payable']) }}</td>
            @if ($hasPrior)<td class="num">{{ F::deduction($pv('interest_payable')) }}</td>@endif
        </tr>
    @endif

    <tr class="gap total">
        <td>{{ __('Profit on ordinary activities before taxation') }}</td><td class="note"></td><td class="num"></td>
        <td class="num cy line-top">{{ F::amount($c['profit_before_tax']) }}</td>
        @if ($hasPrior)<td class="num line-top" style="font-weight:normal;">{{ F::amount($pv('profit_before_tax')) }}</td>@endif
    </tr>
    <tr>
        <td>{{ __('Taxation') }}</td>
        <td class="note">{{ $notes['taxation'] ?? '' }}</td>
        <td class="num"></td>
        <td class="num cy">{{ F::deduction($c['taxation']) }}</td>
        @if ($hasPrior)<td class="num">{{ F::deduction($pv('taxation')) }}</td>@endif
    </tr>
    <tr class="gap total">
        <td>{{ __('Profit on ordinary activities after taxation and total comprehensive income for the year') }}</td><td class="note"></td><td class="num"></td>
        <td class="num cy line-top double">{{ F::amount($c['profit_after_tax']) }}</td>
        @if ($hasPrior)<td class="num line-top double" style="font-weight:normal;">{{ F::amount($pv('profit_after_tax')) }}</td>@endif
    </tr>
@endif
</table>

<div class="footnote">
    @unless ($micro)
        <p>{{ $hasPrior
            ? __('There were no recognised gains or losses for :cy or :py other than those included in the statement of comprehensive income.', ['cy' => $cy, 'py' => $py])
            : __('There were no recognised gains or losses for :cy other than those included in the statement of comprehensive income.', ['cy' => $cy]) }}</p>
    @endunless
    @if ($notesRange !== '')
        <p>{{ __('The notes on :pages form part of these financial statements.', ['pages' => $notesRange]) }}</p>
    @endif
</div>
@endsection
