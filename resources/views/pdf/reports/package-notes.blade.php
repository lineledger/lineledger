<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $heading }} — {{ $company->name }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 36px 0 4px 0; }
        .subtitle { color: #4b5563; margin-bottom: 18px; }
        .notes { line-height: 1.7; }
    </style>
</head>
<body>
    <h1>{{ $heading }}</h1>
    <div class="subtitle">{{ $company->name }}</div>

    <div class="notes">{!! nl2br(e($text)) !!}</div>
</body>
</html>
