<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Sales Tax — {{ $bucketLabel }} — {{ $company->name }}</title>
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
        .tag { color: #6b7280; font-size: 9px; margin-left: 6px; }
    </style>
</head>
<body>
    <h1>Sales Tax — {{ $bucketLabel }}</h1>
    <div class="subtitle">{{ $company->name }} · {{ $agencyName }}</div>
    <div class="meta">
        Period: {{ $startDate }} → {{ $endDate }}<br>
        Generated {{ \Carbon\CarbonImmutable::now()->format('Y-m-d H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Document</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($lines as $line)
            <tr>
                <td>{{ $line['entry_date']->toDateString() }}</td>
                <td>
                    {{ $line['doc_label'] }}
                    @if ($line['is_reversal'])
                        <span class="tag">(reversal)</span>
                    @endif
                </td>
                <td class="num">{{ number_format($line['amount_cents'] / 100, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" style="text-align:center; color:#6b7280;">No activity in this bucket for the selected period.</td></tr>
        @endforelse
        </tbody>
        @if ($lines->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="2" style="text-align:right;">Total</td>
                    <td class="num">{{ number_format($lines->sum('amount_cents') / 100, 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
