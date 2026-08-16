<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>T4A Summary — {{ $company->name }} — {{ $year }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 4px 0; }
        .subtitle { color: #4b5563; margin-bottom: 14px; }
        .meta { color: #6b7280; font-size: 10px; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        thead th { background: #f3f4f6; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: #374151; }
        td.num, th.num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        tfoot td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
        .footer { margin-top: 16px; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    @php($fmt = fn ($c) => number_format($c / 100, 2))
    <h1>T4A Summary {{ $year }}</h1>
    <div class="subtitle">{{ $company->name }}</div>
    <div class="meta">
        Calendar year: {{ $year }}<br>
        Generated {{ \Carbon\CarbonImmutable::now()->format('Y-m-d H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Recipient</th>
                <th>Business # / SIN</th>
                <th class="num">Box 048 — Fees for services</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row['name'] }}{{ $row['meets_threshold'] ? '' : ' (below $500)' }}</td>
                <td>{{ $row['tax_number'] ?: '—' }}</td>
                <td class="num">{{ $fmt($row['box048_cents']) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" style="text-align:center; color:#6b7280;">No T4A-tracked contractors with payments in {{ $year }}.</td></tr>
        @endforelse
        </tbody>
        @if (! empty($rows))
            <tfoot>
                <tr>
                    <td colspan="2" style="text-align:right;">Total ({{ $totals['count'] }})</td>
                    <td class="num">{{ $fmt($totals['total']) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <div class="footer">Includes posted bill payments and posted cheques to T4A-tracked contractors during the calendar year. T4A Box 048 (fees for services). Verify against CRA requirements before issuing.</div>
</body>
</html>
