<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Receipt') }} {{ $receipt->receipt_no }} — {{ $company->name }}</title>
    <style>
        @page { margin: 36px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        table { border-collapse: collapse; }
        .full { width: 100%; }
        .top td { vertical-align: top; padding: 0; border: none; }
        .logo { max-height: 64px; max-width: 220px; margin-bottom: 6px; }
        .company-name { font-size: 15px; font-weight: bold; }
        .muted { color: #4b5563; }
        h1.title { font-size: 34px; font-weight: bold; text-align: right; margin: 0 0 8px 0; letter-spacing: 0.02em; }
        table.meta { border: 1px solid #9ca3af; width: 100%; }
        table.meta th, table.meta td { border: 1px solid #9ca3af; padding: 4px 8px; text-align: center; font-size: 10px; }
        table.meta th { background: #f3f4f6; text-transform: uppercase; letter-spacing: 0.03em; }
        .parties { margin-top: 20px; }
        .parties td { vertical-align: top; padding: 0; border: none; }
        .box { border: 1px solid #9ca3af; min-height: 70px; padding: 6px 8px; }
        .box-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; color: #374151; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; margin-bottom: 4px; }
        table.lines { width: 100%; margin-top: 18px; border: 1px solid #9ca3af; }
        table.lines th, table.lines td { border: 1px solid #d1d5db; padding: 5px 8px; }
        table.lines thead th { background: #f3f4f6; font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em; color: #374151; }
        .num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        table.totals { width: 45%; margin-top: 10px; margin-left: 55%; }
        table.totals td { padding: 4px 8px; }
        table.totals td.num { font-family: DejaVu Sans Mono, monospace; }
        table.totals tr.grand td { border-top: 2px solid #9ca3af; font-size: 14px; font-weight: bold; }
        .footer { margin-top: 28px; border-top: 1px solid #d1d5db; padding-top: 8px; color: #374151; }
        .footer .taxno { font-size: 10px; }
        .footer .message { margin-top: 6px; color: #4b5563; white-space: pre-line; }
    </style>
</head>
<body>
    @php
        $receivedFromLines = collect([
            $receipt->contact?->display_name,
            $receipt->contact?->billing_line1,
            $receipt->contact?->billing_line2,
            collect([$receipt->contact?->billing_city, $receipt->contact?->billing_region, $receipt->contact?->billing_postal_code])->filter()->implode(', '),
        ])->filter()->values();
    @endphp

    <table class="full top">
        <tr>
            <td style="width: 55%;">
                @include('pdf.partials._company-header', [
                    'company' => $company,
                    'settings' => $settings,
                    'documentLogo' => ($settings->show_logo ? $company->documentLogoDataUri() : null),
                    'logoMaxHeight' => $company->documentLogoMaxHeight(),
                ])
            </td>
            <td style="width: 45%;">
                <h1 class="title">{{ __('RECEIPT') }}</h1>
                <table class="meta">
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Receipt #') }}</th>
                    </tr>
                    <tr>
                        <td>{{ $receipt->receipt_date?->format('n/j/Y') }}</td>
                        <td>{{ $receipt->receipt_no }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="full parties">
        <tr>
            <td style="width: 48%;">
                <div class="box">
                    <div class="box-label">{{ __('Received From') }}</div>
                    @foreach ($receivedFromLines as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                </div>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%;">
                <div class="box">
                    <div class="box-label">{{ __('Payment Details') }}</div>
                    <div>{{ __('Method') }}: {{ $receipt->paymentMethod?->name ?? '—' }}</div>
                    <div>{{ __('Reference') }}: {{ $receipt->reference ?? '—' }}</div>
                </div>
            </td>
        </tr>
    </table>

    @if ($receipt->applications->isNotEmpty())
        <table class="lines">
            <thead>
                <tr>
                    <th>{{ __('Invoice') }}</th>
                    <th>{{ __('Invoice Date') }}</th>
                    <th class="num" style="width: 22%;">{{ __('Amount Applied') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($receipt->applications as $app)
                    <tr>
                        <td>{{ optional($app->invoice)->invoice_no }}</td>
                        <td>{{ optional(optional($app->invoice)->invoice_date)->format('n/j/Y') ?? '—' }}</td>
                        <td class="num">{{ number_format($app->amount_cents / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <tr class="grand">
            <td>{{ __('Amount Received') }}</td>
            <td class="num">${{ number_format($receipt->amount_cents / 100, 2) }}</td>
        </tr>
    </table>

    <div class="footer">
        @if ($settings->show_tax_number && filled($company->tax_number))
            <div class="taxno">{{ __('GST/HST No.') }} {{ $company->tax_number }}</div>
        @endif
        @if (filled($settings->footer_message))
            <div class="message">{{ $settings->footer_message }}</div>
        @endif
    </div>
</body>
</html>
