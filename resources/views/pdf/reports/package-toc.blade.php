<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Table of Contents') }} — {{ $company->name }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 36px 0 4px 0; }
        .subtitle { color: #4b5563; margin-bottom: 18px; }
        table.toc { width: 100%; border-collapse: collapse; }
        table.toc td { padding: 8px 4px; border-bottom: 1px solid #e5e7eb; }
        table.toc td.num { width: 28px; color: #6b7280; }
        table.toc td.page { width: 60px; text-align: right; font-family: DejaVu Sans Mono, monospace; }
    </style>
</head>
<body>
    <h1>{{ __('Table of Contents') }}</h1>
    <div class="subtitle">{{ $company->name }}</div>

    <table class="toc">
        @foreach ($entries as $entry)
            <tr>
                <td class="num">{{ $loop->iteration }}.</td>
                <td>{{ $entry['label'] }}</td>
                <td class="page">{{ $entry['page'] }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
