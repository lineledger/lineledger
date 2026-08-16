<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Revenu Québec Remittance — {{ $company->name }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 4px 0; }
        .subtitle { color: #4b5563; margin-bottom: 14px; }
        .meta { color: #6b7280; font-size: 10px; margin-bottom: 18px; }
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .summary td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        .summary td.label { color: #374151; }
        .summary td.num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        .summary tr.total td { font-weight: bold; font-size: 13px; border-top: 2px solid #d1d5db; background: #f0fdfa; }
        table.runs { width: 100%; border-collapse: collapse; }
        table.runs th, table.runs td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        table.runs thead th { background: #f3f4f6; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: #374151; }
        table.runs td.num, table.runs th.num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        .footer { margin-top: 16px; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    @php($fmt = fn ($c) => number_format($c / 100, 2))
    <h1>Revenu Québec — Source Deductions &amp; Employer Contributions (TPZ-1015.R.14)</h1>
    <div class="subtitle">{{ $company->name }}</div>
    <div class="meta">
        Remitting period: {{ $periodLabel }}<br>
        Generated {{ \Carbon\CarbonImmutable::now()->format('Y-m-d H:i') }}
    </div>

    <table class="summary">
        <tr><td class="label">Quebec income tax withheld</td><td class="num">{{ $fmt($summary['quebec_tax_cents']) }}</td></tr>
        <tr><td class="label">Total QPP (employee + employer)</td><td class="num">{{ $fmt($summary['total_qpp_cents']) }}</td></tr>
        <tr><td class="label">Total QPIP (employee + employer)</td><td class="num">{{ $fmt($summary['total_qpip_cents']) }}</td></tr>
        <tr><td class="label">QHSF (employer)</td><td class="num">{{ $fmt($summary['qhsf_cents']) }}</td></tr>
        <tr><td class="label">CNESST (employer)</td><td class="num">{{ $fmt($summary['cnesst_cents']) }}</td></tr>
        <tr class="total"><td class="label">Amount of current payment</td><td class="num">{{ $fmt($summary['remittance_due_cents']) }}</td></tr>
    </table>

    <div class="meta">
        Quebec payroll: {{ $fmt($summary['quebec_gross_cents']) }} &nbsp;·&nbsp;
        Quebec employees in period: {{ $summary['employee_count'] }}
    </div>

    <table class="runs">
        <thead>
            <tr>
                <th>Run #</th><th>Pay date</th><th class="num">Employees</th><th class="num">Quebec gross</th>
                <th class="num">QPP</th><th class="num">QPIP</th><th class="num">Quebec tax</th><th class="num">QHSF + CNESST</th><th class="num">Remittance</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row['run_no'] }}</td>
                <td>{{ $row['pay_date'] }}</td>
                <td class="num">{{ $row['employees'] }}</td>
                <td class="num">{{ $fmt($row['quebec_gross_cents']) }}</td>
                <td class="num">{{ $fmt($row['qpp_cents']) }}</td>
                <td class="num">{{ $fmt($row['qpip_cents']) }}</td>
                <td class="num">{{ $fmt($row['quebec_tax_cents']) }}</td>
                <td class="num">{{ $fmt($row['levies_cents']) }}</td>
                <td class="num">{{ $fmt($row['remittance_cents']) }}</td>
            </tr>
        @empty
            <tr><td colspan="9" style="text-align:center; color:#6b7280;">No posted Quebec pay runs in this period.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="footer">Enter these totals on your TPZ-1015.R.14 in My Account for businesses at Revenu Québec. Includes posted pay runs whose pay date falls in the remitting period; amounts reflect any manual adjustments.</div>
</body>
</html>
