<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>T4 Summary — {{ $company->name }} — {{ $year }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 2px 0; }
        .subtitle { color: #4b5563; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        thead th { background: #f3f4f6; text-align: left; font-size: 10px; text-transform: uppercase; color: #374151; }
        td.num, th.num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        tfoot td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
        .footer { margin-top: 16px; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    @php($fmt = fn ($c) => number_format($c / 100, 2))
    <h1>T4 Summary {{ $year }}</h1>
    <div class="subtitle">{{ $company->name }} · {{ $summary['slip_count'] }} slip(s)</div>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th class="num">14 Income</th><th class="num">16 CPP</th><th class="num">18 EI</th>
                <th class="num">22 Tax</th><th class="num">24 EI ins.</th><th class="num">26 CPP pens.</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($slips as $slip)
            <tr>
                <td>{{ $slip['name'] }}</td>
                <td class="num">{{ $fmt($slip['box14']) }}</td>
                <td class="num">{{ $fmt($slip['box16'] + $slip['box16a']) }}</td>
                <td class="num">{{ $fmt($slip['box18']) }}</td>
                <td class="num">{{ $fmt($slip['box22']) }}</td>
                <td class="num">{{ $fmt($slip['box24']) }}</td>
                <td class="num">{{ $fmt($slip['box26']) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Totals</td>
                <td class="num">{{ $fmt($summary['box14']) }}</td>
                <td class="num">{{ $fmt($summary['box16'] + $summary['box16a']) }}</td>
                <td class="num">{{ $fmt($summary['box18']) }}</td>
                <td class="num">{{ $fmt($summary['box22']) }}</td>
                <td class="num">{{ $fmt($summary['box24']) }}</td>
                <td class="num">{{ $fmt($summary['box26']) }}</td>
            </tr>
        </tfoot>
    </table>

    <p style="margin-top:14px;">
        Employer CPP contributions: <strong>{{ $fmt($summary['employer_cpp']) }}</strong> &nbsp;·&nbsp;
        Employer EI contributions: <strong>{{ $fmt($summary['employer_ei']) }}</strong>
    </p>

    <div class="footer">Generated {{ \Carbon\CarbonImmutable::now()->format('Y-m-d H:i') }}. Figures from posted pay runs in {{ $year }}.</div>
</body>
</html>
