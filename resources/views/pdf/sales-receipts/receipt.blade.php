<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Sales Receipt') }} {{ $receipt->sales_receipt_no }} — {{ $company->name }}</title>
    <style>
        @page { margin: 36px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        table { border-collapse: collapse; }
        .full { width: 100%; }
        .top td { vertical-align: top; padding: 0; border: none; }
        .logo { max-height: 64px; max-width: 220px; margin-bottom: 6px; }
        .company-name { font-size: 15px; font-weight: bold; }
        .muted { color: #4b5563; }
        h1.title { font-size: 32px; font-weight: bold; text-align: right; margin: 0 0 8px 0; letter-spacing: 0.02em; }
        .paid { text-align: right; color: #047857; font-weight: bold; font-size: 13px; letter-spacing: 0.08em; }
        table.meta { border: 1px solid #9ca3af; width: 100%; margin-top: 4px; }
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
        $billToLines = collect([
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
                <h1 class="title">{{ __('SALES RECEIPT') }}</h1>
                <div class="paid">{{ __('PAID') }}</div>
                <table class="meta">
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Receipt #') }}</th>
                    </tr>
                    <tr>
                        <td>{{ $receipt->receipt_date?->format('n/j/Y') }}</td>
                        <td>{{ $receipt->sales_receipt_no }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="full parties">
        <tr>
            <td style="width: 48%;">
                <div class="box">
                    <div class="box-label">{{ __('Sold To') }}</div>
                    @forelse ($billToLines as $line)
                        <div>{{ $line }}</div>
                    @empty
                        <div class="muted">{{ __('Cash sale') }}</div>
                    @endforelse
                </div>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%;">
                <div class="box">
                    <div class="box-label">{{ __('Payment Details') }}</div>
                    <div>{{ __('Method') }}: {{ $receipt->paymentMethod?->name ?? '—' }}</div>
                    <div>{{ __('Reference') }}: {{ $receipt->reference ?? '—' }}</div>
                    <div>{{ __('Deposited to') }}: {{ $receipt->depositToAccount?->name }}</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('Description') }}</th>
                <th class="num" style="width: 10%;">{{ __('Qty') }}</th>
                <th class="num" style="width: 16%;">{{ __('Unit Price') }}</th>
                <th style="width: 10%;">{{ __('Tax') }}</th>
                <th class="num" style="width: 18%;">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($receipt->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                    <td class="num">{{ number_format($line->unit_price_cents / 100, 2) }}</td>
                    <td>{{ $line->taxCode?->code ?? '—' }}</td>
                    <td class="num">{{ number_format($line->line_total_cents / 100, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>{{ __('Subtotal') }}</td>
            <td class="num">{{ number_format($receipt->subtotal_cents / 100, 2) }}</td>
        </tr>
        <tr>
            <td>{{ __('Tax') }}</td>
            <td class="num">{{ number_format($receipt->tax_cents / 100, 2) }}</td>
        </tr>
        <tr class="grand">
            <td>{{ __('Total Paid') }}</td>
            <td class="num">${{ number_format($receipt->total_cents / 100, 2) }}</td>
        </tr>
    </table>

    <div class="footer">
        @if ($settings->show_tax_number && filled($company->tax_number))
            <div class="taxno">{{ __('GST/HST No.') }} {{ $company->tax_number }}</div>
        @endif
        @if (filled($receipt->memo))
            <div class="message">{{ $receipt->memo }}</div>
        @endif
        @if (filled($settings->footer_message))
            <div class="message">{{ $settings->footer_message }}</div>
        @endif
    </div>
</body>
</html>
