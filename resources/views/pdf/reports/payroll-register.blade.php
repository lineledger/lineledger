@php($money = fn ($cents) => 'CA$'.number_format((int) $cents / 100, 2, '.', ','))
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1a1a1a; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 3px 5px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #eff1f4; }
        td.amt, th.amt { text-align: right; font-family: 'DejaVu Sans Mono', monospace; white-space: nowrap; }
        tr.total td { border-top: 2px solid #999; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $company->name }}</h1>
    <div class="muted">{{ __('Payroll Register') }} · {{ $startDate }} – {{ $endDate }}</div>

    <table>
        <thead>
            <tr>
                <th>{{ __('Employee') }}</th>
                <th>{{ __('Run #') }}</th>
                <th>{{ __('Pay date') }}</th>
                <th class="amt">{{ __('Gross') }}</th>
                <th class="amt">{{ __('CPP/QPP') }}</th>
                <th class="amt">{{ __('EI/QPIP') }}</th>
                <th class="amt">{{ __('Tax') }}</th>
                <th class="amt">{{ __('Other ded.') }}</th>
                <th class="amt">{{ __('Employer') }}</th>
                <th class="amt">{{ __('Net') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>{{ $r['name'] }}</td>
                    <td>{{ $r['run_no'] }}</td>
                    <td>{{ $r['pay_date'] }}</td>
                    <td class="amt">{{ $money($r['gross_cents']) }}</td>
                    <td class="amt">{{ $money($r['cpp_cents']) }}</td>
                    <td class="amt">{{ $money($r['ei_cents']) }}</td>
                    <td class="amt">{{ $money($r['tax_cents']) }}</td>
                    <td class="amt">{{ $money($r['deductions_cents']) }}</td>
                    <td class="amt">{{ $money($r['employer_cents']) }}</td>
                    <td class="amt">{{ $money($r['net_cents']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>{{ __('Total') }}</td>
                <td></td>
                <td></td>
                <td class="amt">{{ $money($summary['gross_cents']) }}</td>
                <td class="amt">{{ $money($summary['cpp_cents']) }}</td>
                <td class="amt">{{ $money($summary['ei_cents']) }}</td>
                <td class="amt">{{ $money($summary['tax_cents']) }}</td>
                <td class="amt">{{ $money($summary['deductions_cents']) }}</td>
                <td class="amt">{{ $money($summary['employer_cents']) }}</td>
                <td class="amt">{{ $money($summary['net_cents']) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
