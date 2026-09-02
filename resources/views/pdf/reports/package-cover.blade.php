<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $company->name }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; }
        .cover { text-align: center; margin-top: 230px; }
        .logo { max-height: 90px; max-width: 260px; margin-bottom: 28px; }
        .company { font-size: 14px; text-transform: uppercase; letter-spacing: 0.12em; color: #6b7280; margin-bottom: 18px; }
        h1 { font-size: 30px; margin: 0 0 10px 0; font-weight: bold; }
        .subtitle { font-size: 15px; color: #4b5563; margin-bottom: 26px; }
        .rule { width: 120px; border-top: 2px solid #d1d5db; margin: 0 auto 26px auto; }
        .period { font-size: 13px; color: #374151; }
        .comparison { font-size: 12px; color: #6b7280; margin-top: 6px; }
    </style>
</head>
<body>
    <div class="cover">
        @if (! empty($logoData))
            <div><img src="{{ $logoData }}" class="logo" alt=""></div>
        @endif
        <div class="company">{{ $company->name }}</div>
        <h1>{{ $title }}</h1>
        @if (! empty($subtitle))
            <div class="subtitle">{{ $subtitle }}</div>
        @endif
        <div class="rule"></div>
        <div class="period">{{ $period }}</div>
        @if (! empty($comparison))
            <div class="comparison">{{ __('Compared to :basis', ['basis' => __($comparison)]) }}</div>
        @endif
    </div>
</body>
</html>
