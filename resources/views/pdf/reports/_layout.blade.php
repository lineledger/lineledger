<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Report' }} — {{ $company->name }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        .header { width: 100%; }
        .header td { border: none; vertical-align: top; padding: 0; }
        h1 { font-size: 18px; margin: 0 0 4px 0; }
        .subtitle { color: #4b5563; margin-bottom: 4px; }
        .meta { color: #6b7280; font-size: 10px; margin-bottom: 14px; }
        .generated { color: #6b7280; font-size: 10px; text-align: right; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.data th, table.data td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        table.data thead th { background: #f3f4f6; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: #374151; }
        table.data td.num, table.data th.num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        table.data tfoot td, table.data tr.total td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
        table.data tr.section td { background: #eef2ff; font-weight: bold; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; }
        table.data tr.subtotal td { background: #f9fafb; font-weight: bold; }
        table.data tr.subsection td { font-weight: bold; color: #4b5563; font-size: 10px; }
        table.data tr.italic td { font-style: italic; color: #6b7280; }
        .footer { margin-top: 16px; color: #6b7280; font-size: 9px; }
        .notes { margin-top: 16px; color: #6b7280; font-size: 9px; white-space: pre-line; border-top: 1px solid #e5e7eb; padding-top: 6px; }
        .neg { color: #b91c1c; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>{{ $title }}</h1>
                <div class="subtitle">{{ $company->name }}{{ isset($period) ? ' · '.$period : '' }}</div>
                @isset($metaLines)
                    @foreach ($metaLines as $line)
                        <div class="meta">{{ $line }}</div>
                    @endforeach
                @endisset
            </td>
            <td class="generated" style="width: 30%;">
                Generated<br>{{ \Carbon\CarbonImmutable::now()->format('Y-m-d H:i') }}
            </td>
        </tr>
    </table>

    @yield('content')

    @if (! empty($notes))
        <div class="notes">{{ $notes }}</div>
    @endif

    @hasSection('footer')
        <div class="footer">@yield('footer')</div>
    @endif
</body>
</html>
