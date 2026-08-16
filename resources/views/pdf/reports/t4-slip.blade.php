<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>T4 — {{ $slip['name'] }} — {{ $year }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 2px 0; }
        .subtitle { color: #4b5563; margin-bottom: 14px; }
        .meta { color: #6b7280; font-size: 10px; margin-bottom: 16px; }
        .boxes { width: 100%; border-collapse: collapse; }
        .boxes td { width: 33%; padding: 8px; border: 1px solid #d1d5db; vertical-align: top; }
        .box-label { color: #6b7280; font-size: 9px; text-transform: uppercase; }
        .box-value { font-family: DejaVu Sans Mono, monospace; font-size: 14px; font-weight: bold; }
        .other { margin-top: 16px; }
        .other table { width: 100%; border-collapse: collapse; }
        .other td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
        .other td.num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        .footer { margin-top: 18px; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    @php($fmt = fn ($c) => number_format($c / 100, 2))
    <h1>T4 — Statement of Remuneration Paid</h1>
    <div class="subtitle">{{ $company->name }} · Tax year {{ $year }}</div>
    <div class="meta">
        Employee: {{ $slip['name'] }}@if ($slip['sin_last4']) · SIN •••• {{ $slip['sin_last4'] }}@endif
        @if ($slip['province']) · Province of employment (Box 10): {{ $slip['province'] }}@endif
    </div>

    <table class="boxes">
        <tr>
            <td><div class="box-label">Box 14 — Employment income</div><div class="box-value">{{ $fmt($slip['box14']) }}</div></td>
            <td><div class="box-label">Box 16 — Employee CPP</div><div class="box-value">{{ $fmt($slip['box16']) }}</div></td>
            <td><div class="box-label">Box 16A — Employee CPP2</div><div class="box-value">{{ $fmt($slip['box16a']) }}</div></td>
        </tr>
        <tr>
            <td><div class="box-label">Box 18 — Employee EI</div><div class="box-value">{{ $fmt($slip['box18']) }}</div></td>
            <td><div class="box-label">Box 22 — Income tax deducted</div><div class="box-value">{{ $fmt($slip['box22']) }}</div></td>
            <td><div class="box-label">Box 24 — EI insurable earnings</div><div class="box-value">{{ $fmt($slip['box24']) }}</div></td>
        </tr>
        <tr>
            <td><div class="box-label">Box 26 — CPP/QPP pensionable earnings</div><div class="box-value">{{ $fmt($slip['box26']) }}</div></td>
            <td>@if (($slip['box17'] ?? 0) > 0)<div class="box-label">Box 17 — Employee QPP</div><div class="box-value">{{ $fmt($slip['box17']) }}</div>@endif</td>
            <td>@if (($slip['box17a'] ?? 0) > 0)<div class="box-label">Box 17A — Employee QPP2</div><div class="box-value">{{ $fmt($slip['box17a']) }}</div>@endif</td>
        </tr>
        @if (($slip['box55'] ?? 0) > 0 || ($slip['box56'] ?? 0) > 0)
            <tr>
                <td><div class="box-label">Box 55 — QPIP premiums</div><div class="box-value">{{ $fmt($slip['box55'] ?? 0) }}</div></td>
                <td><div class="box-label">Box 56 — QPIP insurable earnings</div><div class="box-value">{{ $fmt($slip['box56'] ?? 0) }}</div></td>
                <td></td>
            </tr>
        @endif
    </table>

    @if (! empty($slip['other']))
        <div class="other">
            <strong>Other information</strong>
            <table>
                @foreach ($slip['other'] as $box => $amount)
                    <tr><td>Box {{ $box }}</td><td class="num">{{ $fmt($amount) }}</td></tr>
                @endforeach
            </table>
        </div>
    @endif

    @if ($facsimile ?? false)
        <div class="footer" style="font-weight: bold;">FACSIMILE — the official CRA {{ $year }} T4 template is not installed. Box figures are identical to the official form; install a flattened copy of the fillable T4 at storage/app/slip-templates/{{ $year }}/t4.pdf to print on the CRA form.</div>
    @endif
    <div class="footer">Generated {{ \Carbon\CarbonImmutable::now()->format('Y-m-d H:i') }}. Figures from posted pay runs in {{ $year }}. Verify against CRA requirements before issuing.</div>
</body>
</html>
