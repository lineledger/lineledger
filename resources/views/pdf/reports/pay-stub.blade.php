@php
    $money = fn ($cents) => 'CA$'.number_format((int) $cents / 100, 2, '.', ',');
    $showYtd = $show['ytd'];
    $cols = $showYtd ? 3 : 2;

    // Employer address (shown where the jurisdiction requires it / the employer opts in).
    $employerAddress = collect([
        $company->address_line1, $company->address_line2,
        collect([$company->address_city, $company->address_region, $company->address_postal_code])->filter()->join(' '),
    ])->filter()->join(', ');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .muted { color: #666; }
        .small { font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4px 6px; vertical-align: top; }
        th { text-align: left; }
        td.amt, th.amt { text-align: right; font-family: 'DejaVu Sans Mono', monospace; white-space: nowrap; }
        .section-title { font-size: 12px; font-weight: bold; border-bottom: 1.5px solid #333; padding-bottom: 3px; margin: 16px 0 2px; }
        thead.cols th { border-bottom: 1px solid #ccc; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; color: #666; }
        tr.totals td { border-top: 1px solid #ccc; font-weight: bold; }
        .summary td { padding: 6px; }
        .summary .net td { border-top: 2px solid #333; font-size: 14px; font-weight: bold; }
        .preview-tag { float: right; font-size: 10px; color: #b45309; border: 1px solid #f59e0b; border-radius: 4px; padding: 2px 6px; }
        .header-grid { width: 100%; margin-top: 10px; }
        .footer { margin-top: 22px; border-top: 1px solid #e5e7eb; padding-top: 8px; }
    </style>
</head>
<body>
    {{-- Header: statement name (per jurisdiction) + employer --}}
    <div>
        @unless ($line->payRun->status->isPosted())
            <span class="preview-tag">{{ __('PREVIEW — not yet posted') }}</span>
        @endunless
        <h1>{{ $jurisdiction['name'] }}</h1>
        <div class="muted">{{ $company->name }}@if ($show['employer_address'] && $employerAddress) · {{ $employerAddress }}@endif</div>
    </div>

    {{-- Employee + pay period --}}
    <table class="header-grid">
        <tr>
            <td>
                <strong>{{ $line->contact->display_name }}</strong>
                @if ($line->contact->employee_id)
                    <br><span class="muted small">{{ __('Employee #:id', ['id' => $line->contact->employee_id]) }}</span>
                @endif
                @if ($show['occupation'] && $line->contact->job_title)
                    <br><span class="muted small">{{ $line->contact->job_title }}</span>
                @endif
                @if ($line->profile?->sin_last4)
                    <br><span class="muted small">{{ __('SIN') }} •••&nbsp;{{ $line->profile->sin_last4 }}</span>
                @endif
            </td>
            <td class="amt">
                {{ __('Pay date') }}: {{ $line->payRun->pay_date->format('M j, Y') }}<br>
                <span class="muted small">{{ __('Period') }}: {{ $line->payRun->period_start_date->format('M j') }} – {{ $line->payRun->period_end_date->format('M j, Y') }}</span><br>
                <span class="muted small">{{ __('Run') }} {{ $line->payRun->run_no }}</span>
                @if (($show['hours'] && (float) $line->hours_worked > 0) || ($show['rate'] && (int) $line->hourly_rate_cents > 0))
                    <br><span class="muted small">
                        @if ($show['hours'] && (float) $line->hours_worked > 0){{ __('Hours') }}: {{ number_format((float) $line->hours_worked, 2) }}@endif
                        @if ($show['rate'] && (int) $line->hourly_rate_cents > 0) · {{ __('Rate') }}: {{ $money($line->hourly_rate_cents) }}/{{ __('hr') }}@endif
                    </span>
                @endif
            </td>
        </tr>
    </table>

    {{-- Earnings --}}
    <div class="section-title">{{ __('Earnings') }}</div>
    <table>
        <thead class="cols"><tr>
            <th>{{ __('Description') }}</th>
            <th class="amt">{{ __('Current') }}</th>
            @if ($showYtd)<th class="amt">{{ __('YTD') }}</th>@endif
        </tr></thead>
        <tbody>
            @foreach ($cashEarnings as $e)
                <tr>
                    <td>{{ $e['name'] }}</td>
                    <td class="amt">{{ $money($e['current_cents']) }}</td>
                    @if ($showYtd)<td class="amt">{{ $money($e['ytd_cents']) }}</td>@endif
                </tr>
            @endforeach
            <tr class="totals">
                <td>{{ __('Gross earnings') }}</td>
                <td class="amt">{{ $money($ytd['gross_current']) }}</td>
                @if ($showYtd)<td class="amt">{{ $money($ytd['gross_ytd']) }}</td>@endif
            </tr>
        </tbody>
    </table>

    {{-- Employer-paid benefits & accruals (non-cash; not part of net) --}}
    @if ($show['benefits_section'] && (! empty($ytd['benefits']) || ! empty($benefitEarnings) || $line->accruals->isNotEmpty() || (int) $line->vacation_accrued_cents > 0))
        <div class="section-title">{{ __('Employer-paid benefits & accruals') }}</div>
        <table>
            <thead class="cols"><tr>
                <th>{{ __('Description') }}</th>
                <th class="amt">{{ __('Current') }}</th>
                @if ($showYtd)<th class="amt">{{ __('YTD') }}</th>@endif
            </tr></thead>
            <tbody>
                @foreach ($ytd['benefits'] as $b)
                    <tr>
                        <td>{{ $b['name'] }}</td>
                        <td class="amt">{{ $money($b['current_cents']) }}</td>
                        @if ($showYtd)<td class="amt">{{ $money($b['ytd_cents']) }}</td>@endif
                    </tr>
                @endforeach
                @foreach ($benefitEarnings as $b)
                    <tr>
                        <td>{{ $b['name'] }} <span class="muted small">({{ __('taxable benefit') }})</span></td>
                        <td class="amt">{{ $money($b['current_cents']) }}</td>
                        @if ($showYtd)<td class="amt">{{ $money($b['ytd_cents']) }}</td>@endif
                    </tr>
                @endforeach
                @if ((int) $line->vacation_accrued_cents > 0)
                    <tr>
                        <td>{{ __('Vacation accrued') }}</td>
                        <td class="amt">{{ $money($line->vacation_accrued_cents) }}</td>
                        @if ($showYtd)<td class="amt"></td>@endif
                    </tr>
                @endif
                @foreach ($ytd['accruals'] as $a)
                    <tr>
                        <td>{{ $a['name'] }}</td>
                        <td class="amt">{{ (int) $a['current_cents'] !== 0 ? $money($a['current_cents']) : number_format((float) $a['current_hours'], 2).' '.__('hrs') }}</td>
                        @if ($showYtd)<td class="amt">{{ (int) $a['ytd_cents'] !== 0 ? $money($a['ytd_cents']) : number_format((float) $a['ytd_hours'], 2).' '.__('hrs') }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="muted small" style="margin: 2px 6px;">{{ __('Employer-paid amounts are not deducted from your pay.') }}</p>
    @endif

    {{-- Deductions --}}
    <div class="section-title">{{ __('Deductions') }}</div>
    <table>
        <thead class="cols"><tr>
            <th>{{ __('Description') }}</th>
            <th class="amt">{{ __('Current') }}</th>
            @if ($showYtd)<th class="amt">{{ __('YTD') }}</th>@endif
        </tr></thead>
        <tbody>
            @foreach ($statutory as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="amt">{{ $money($row['current']) }}</td>
                    @if ($showYtd)<td class="amt">{{ $money($row['ytd']) }}</td>@endif
                </tr>
            @endforeach
            @foreach ($ytd['deductions'] as $d)
                <tr>
                    <td>{{ $d['name'] }}</td>
                    <td class="amt">{{ $money($d['current_cents']) }}</td>
                    @if ($showYtd)<td class="amt">{{ $money($d['ytd_cents']) }}</td>@endif
                </tr>
            @endforeach
            <tr class="totals">
                <td>{{ __('Total deductions') }}</td>
                <td class="amt">{{ $money($ytd['deductions_current']) }}</td>
                @if ($showYtd)<td class="amt">{{ $money($ytd['deductions_ytd']) }}</td>@endif
            </tr>
        </tbody>
    </table>

    {{-- Summary --}}
    <table class="summary" style="margin-top: 14px; background: #f3f4f6;">
        <thead class="cols"><tr>
            <th></th>
            <th class="amt">{{ __('Current') }}</th>
            @if ($showYtd)<th class="amt">{{ __('YTD') }}</th>@endif
        </tr></thead>
        <tbody>
            <tr>
                <td>{{ __('Gross') }}</td>
                <td class="amt">{{ $money($ytd['gross_current']) }}</td>
                @if ($showYtd)<td class="amt">{{ $money($ytd['gross_ytd']) }}</td>@endif
            </tr>
            <tr>
                <td>{{ __('Deductions') }}</td>
                <td class="amt">{{ $money($ytd['deductions_current']) }}</td>
                @if ($showYtd)<td class="amt">{{ $money($ytd['deductions_ytd']) }}</td>@endif
            </tr>
            <tr class="net">
                <td>{{ __('Net pay') }}</td>
                <td class="amt">{{ $money($ytd['net_current']) }}</td>
                @if ($showYtd)<td class="amt">{{ $money($ytd['net_ytd']) }}</td>@endif
            </tr>
        </tbody>
    </table>

    {{-- Footer: legislation reference + retention --}}
    <div class="footer muted small">
        <div>{{ __('Statement prepared in accordance with :legislation.', ['legislation' => $jurisdiction['legislation']]) }}</div>
        <div>{{ __('Retain for :retention.', ['retention' => $jurisdiction['retention']]) }}</div>
        <div style="margin-top: 4px;">
            {{ __('Generated by LineLedger.') }}
            @unless ($line->payRun->status->isPosted())
                {{ __('This is a preview of a pay run that has not been posted; figures may change.') }}
            @endunless
        </div>
    </div>
</body>
</html>
