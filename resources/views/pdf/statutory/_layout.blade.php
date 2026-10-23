<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $companyName }}</title>
    <style>
        @page { margin: 37mm 18mm 16mm 18mm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 8.7pt; color: #000; line-height: 1.32; }
        p { margin: 0 0 7pt 0; text-align: justify; }
        h3 { font-size: 8.7pt; font-weight: bold; margin: 10pt 0 2pt 0; }
        sup { font-size: 5.5pt; vertical-align: super; line-height: 0; }
        table.fs { width: 100%; border-collapse: collapse; }
        table.fs td, table.fs th { padding: 0.6pt 0 0.6pt 0; vertical-align: bottom; }
        table.fs th { font-weight: bold; text-align: right; }
        table.fs td.num, table.fs th.num { text-align: right; width: 21mm; padding-left: 4mm; }
        table.fs td.note, table.fs th.note { text-align: center; width: 12mm; }
        table.fs td.cy, table.fs th.cy { font-weight: bold; }
        table.fs tr.head td { font-weight: bold; padding-top: 8pt; }
        table.fs tr.total td { font-weight: bold; }
        table.fs tr.gap td { padding-top: 7pt; }
        .line-top { border-top: 0.6pt solid #000; }
        .line-bottom { border-bottom: 0.6pt solid #000; }
        .double { border-bottom: 2.2pt double #000; }
        .muted { color: #000; }
        .footnote { position: fixed; bottom: 0; left: 0; right: 0; font-size: 7.8pt; }
        .signatures { width: 100%; margin-top: 44pt; }
        .signatures td { width: 50%; vertical-align: top; padding-right: 10mm; }
        .dots { letter-spacing: 0.5pt; }
        ul.dash { margin: 2pt 0 7pt 10mm; padding: 0; list-style: none; }
        ul.dash li { margin-bottom: 1pt; text-align: justify; }
        .notehead { font-weight: bold; margin: 12pt 0 4pt 0; }
        .notehead .no { display: inline-block; width: 8mm; }
        .indent { margin-left: 8mm; }
        .keep { page-break-inside: avoid; }
    </style>
</head>
<body>
@yield('content')
</body>
</html>
