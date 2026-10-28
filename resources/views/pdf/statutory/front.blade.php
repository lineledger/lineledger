<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $companyName }}</title>
    <style>
        @page { margin: 14mm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9.6pt; color: #000; }
        sup { font-size: 6pt; vertical-align: super; line-height: 0; }
        .cover { border: 3pt double #000; height: 262mm; text-align: center; }
        .cover .inner { padding-top: 92mm; }
        .cover .name { font-size: 16pt; line-height: 1.3; margin-bottom: 12pt; }
        .cover .what { font-size: 12pt; line-height: 1.4; }
        .page { padding: 10mm 14mm 0 14mm; }
        .title { text-align: center; font-size: 13pt; margin: 8mm 0 0 0; }
        .subtitle { text-align: center; font-size: 11pt; margin: 9mm 0 14mm 0; }
        table.info { width: 100%; border-collapse: collapse; font-size: 9.6pt; }
        table.info td { vertical-align: top; padding: 0 0 13pt 0; }
        table.info td.k { width: 46%; padding-left: 12mm; }
        table.contents { width: 100%; border-collapse: collapse; font-size: 9.6pt; }
        table.contents td { padding: 0 0 11pt 0; }
        table.contents td.k { padding-left: 18mm; }
        table.contents td.p { text-align: center; width: 30mm; }
        .break { page-break-after: always; }
    </style>
</head>
<body>
    @php
        $period = (! $micro || $profile->includeDirectorsReport)
            ? __("Directors' report & Financial Statements")
            : __('Financial Statements');
    @endphp

    {{-- Cover --}}
    <div class="cover break">
        <div class="inner">
            <div class="name">{!! nl2br(e(wordwrap($companyName, 22))) !!}</div>
            <div class="what">{{ $period }}<br>{{ __('For the year ended') }}<br><br>{!! $yearEnded !!}</div>
        </div>
    </div>

    {{-- Company information --}}
    <div class="page break">
        <div class="title">{{ $companyName }}</div>
        <div class="subtitle">{{ __('Company Information') }}</div>
        <table class="info">
            @if ($profile->directors !== [])
                <tr><td class="k">{{ count($profile->directors) === 1 ? __('Director') : __('Directors') }}</td><td>{!! collect($profile->directors)->pluck('name')->map(fn ($n) => e($n))->implode('<br>') !!}</td></tr>
            @endif
            @if ($profile->secretary !== '')
                <tr><td class="k">{{ __('Secretary') }}</td><td>{{ $profile->secretary }}</td></tr>
            @endif
            @if ($profile->companyNumber !== '')
                <tr><td class="k">{{ __('Company Number') }}</td><td>{{ $profile->companyNumber }}@if ($profile->isIsleOfMan()) ({{ __('Isle of Man') }})@endif</td></tr>
            @endif
            @if ($profile->registeredOffice !== '')
                <tr><td class="k">{{ __('Registered Office') }}</td><td>{!! nl2br(e($profile->registeredOffice)) !!}</td></tr>
            @endif
        </table>
    </div>

    {{-- Contents --}}
    <div class="page">
        <div class="title">{{ $companyName }}</div>
        <div class="subtitle">{{ __('Contents') }}</div>
        <table class="contents">
            <tr><td></td><td class="p"><strong>{{ __('Page') }}</strong></td></tr>
            @foreach ($contents as $row)
                <tr><td class="k">{{ $row['title'] }}</td><td class="p">{{ $row['pages'] }}</td></tr>
            @endforeach
        </table>
    </div>
</body>
</html>
