<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>ROE worksheet — {{ $roe['employee'] }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 2px 0; }
        .subtitle { color: #4b5563; margin-bottom: 14px; }
        .blocks { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .blocks td { padding: 6px 8px; border: 1px solid #d1d5db; }
        .blocks td.label { color: #6b7280; width: 40%; }
        table.periods { width: 100%; border-collapse: collapse; }
        table.periods th, table.periods td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
        table.periods thead th { background: #f3f4f6; text-align: left; font-size: 10px; text-transform: uppercase; color: #374151; }
        table.periods td.num, table.periods th.num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        tfoot td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
        .footer { margin-top: 16px; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    @php($fmt = fn ($c) => number_format($c / 100, 2))
    <h1>Record of Employment — Worksheet</h1>
    <div class="subtitle">{{ $company->name }}</div>

    <table class="blocks">
        <tr><td class="label">Employee</td><td>{{ $roe['employee'] }}@if ($roe['sin_last4']) (SIN •••• {{ $roe['sin_last4'] }})@endif</td></tr>
        <tr><td class="label">Block 10 — First day worked</td><td>{{ $roe['first_day'] ?? '—' }}</td></tr>
        <tr><td class="label">Block 11 — Last day for which paid</td><td>{{ $roe['last_day'] }}</td></tr>
        <tr><td class="label">Block 12 — Final pay period ending</td><td>{{ $roe['final_period_end'] ?? '—' }}</td></tr>
        <tr><td class="label">Block 16 — Reason for issuing</td><td>{{ $roe['reason_label'] }}</td></tr>
        <tr><td class="label">Block 15A — Total insurable hours</td><td>{{ $roe['total_insurable_hours'] }}</td></tr>
        <tr><td class="label">Block 15B — Total insurable earnings</td><td>{{ $fmt($roe['total_insurable_earnings_cents']) }}</td></tr>
    </table>

    <strong>Block 15C — Insurable earnings by pay period (most recent first)</strong>
    <table class="periods">
        <thead>
            <tr><th>Pay period ending</th><th class="num">Insurable hours</th><th class="num">Insurable earnings</th></tr>
        </thead>
        <tbody>
        @foreach ($roe['periods'] as $p)
            <tr>
                <td>{{ $p['period_end'] }}</td>
                <td class="num">{{ $p['insurable_hours'] }}</td>
                <td class="num">{{ $fmt($p['insurable_earnings_cents']) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr><td>Total</td><td class="num">{{ $roe['total_insurable_hours'] }}</td><td class="num">{{ $fmt($roe['total_insurable_earnings_cents']) }}</td></tr>
        </tfoot>
    </table>

    <div class="footer">Generated {{ \Carbon\CarbonImmutable::now()->format('Y-m-d H:i') }}. Transcribe into Service Canada ROE Web. Electronic submission is not provided.</div>
</body>
</html>
