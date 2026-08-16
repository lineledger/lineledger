<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Sales Tax — {{ $company->name }}</title>
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
    <h1>Sales Tax</h1>
    <div class="subtitle">{{ $company->name }}</div>
    <div class="meta">
        Period: {{ $startDate }} → {{ $endDate }}<br>
        Generated {{ \Carbon\CarbonImmutable::now()->format('Y-m-d H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Agency</th>
                <th>Payable account</th>
                <th class="num">Collected on sales</th>
                <th class="num">Paid (ITC)</th>
                <th class="num">Net owing</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row['agency'] }}</td>
                <td>{{ $row['payable_account'] }}</td>
                <td class="num">{{ number_format($row['collected'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['paid'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['net'] / 100, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center; color:#6b7280;">No tax agencies configured.</td></tr>
        @endforelse
        </tbody>
        @if (! empty($rows))
            <tfoot>
                <tr>
                    <td colspan="2" style="text-align:right;">Totals</td>
                    <td class="num">{{ number_format($totals['collected'] / 100, 2) }}</td>
                    <td class="num">{{ number_format($totals['paid'] / 100, 2) }}</td>
                    <td class="num">{{ number_format($totals['net'] / 100, 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <div class="footer">Net owing is what you remit to the agency. Negative values mean the agency owes you a refund.</div>
</body>
</html>
