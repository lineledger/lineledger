<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Invoice') }} {{ $invoice->invoice_no }} — {{ $company->name }}</title>
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
    @php
        // Optional FormStyle override (per-invoice template). Some callers render
        // this blade without a 'style' key, so guard the variable itself.
        $style = $style ?? null;

        $accent = $style?->accent_color;
        // A 90%-white tint of the accent for table-header backgrounds, so the
        // header text stays readable (dompdf-safe: plain 6-digit hex).
        $accentTint = $accent ? sprintf(
            '#%02x%02x%02x',
            (int) round(hexdec(substr($accent, 1, 2)) * 0.1 + 255 * 0.9),
            (int) round(hexdec(substr($accent, 3, 2)) * 0.1 + 255 * 0.9),
            (int) round(hexdec(substr($accent, 5, 2)) * 0.1 + 255 * 0.9),
        ) : null;
    @endphp
    @if ($accent)
        <style>
            h1.title { color: {{ $accent }}; }
            table.meta th { background: {{ $accentTint }}; }
            table.lines thead th { background: {{ $accentTint }}; }
            table.totals tr.grand td { border-top-color: {{ $accent }}; }
        </style>
    @endif
</head>
<body>
    @php
        $billLines = collect([
            $invoice->contact?->display_name,
            $invoice->contact?->billing_line1,
            $invoice->contact?->billing_line2,
            collect([$invoice->contact?->billing_city, $invoice->contact?->billing_region, $invoice->contact?->billing_postal_code])->filter()->implode(', '),
        ])->filter()->values();

        $shipLines = collect([
            $invoice->contact?->shipping_line1,
            $invoice->contact?->shipping_line2,
            collect([$invoice->contact?->shipping_city, $invoice->contact?->shipping_region, $invoice->contact?->shipping_postal_code])->filter()->implode(', '),
        ])->filter()->values();

        // Description + Amount always show; the rest are toggleable.
        $columns = 2
            + ($settings->show_qty_column ? 1 : 0)
            + ($settings->show_item_column ? 1 : 0)
            + ($settings->show_tax_column ? 1 : 0)
            + ($settings->show_unit_column ? 1 : 0);
    @endphp

    <table class="full top">
        <tr>
            <td style="width: 55%;">
                @include('pdf.partials._company-header', [
                    'company' => $company,
                    'settings' => $settings,
                    'documentLogo' => (($style?->show_logo ?? $settings->show_logo) ? $company->documentLogoDataUri() : null),
                    'logoMaxHeight' => $company->documentLogoMaxHeight(),
                ])
            </td>
            <td style="width: 45%;">
                <h1 class="title">{{ __('INVOICE') }}</h1>
                <table class="meta">
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Invoice #') }}</th>
                    </tr>
                    <tr>
                        <td>{{ $invoice->invoice_date?->format('n/j/Y') }}</td>
                        <td>{{ $invoice->invoice_no }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="full parties">
        <tr>
            <td style="width: 48%;">
                <div class="box">
                    <div class="box-label">{{ __('Bill To') }}</div>
                    @foreach ($billLines as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                </div>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%;">
                <div class="box">
                    <div class="box-label">{{ __('Ship To') }}</div>
                    @foreach ($shipLines as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                </div>
            </td>
        </tr>
    </table>

    <table class="meta full" style="margin-top: 14px;">
        <tr>
            <th>{{ __('Terms') }}</th>
            <th>{{ __('Due Date') }}</th>
            <th>{{ __('P.O. #') }}</th>
            <th>{{ __('Rep') }}</th>
        </tr>
        <tr>
            <td>{{ optional($invoice->terms)->name ?? '—' }}</td>
            <td>{{ $invoice->due_date?->format('n/j/Y') ?? '—' }}</td>
            <td>{{ $invoice->customer_po ?: '—' }}</td>
            <td>{{ optional($invoice->salesRep)->display_name ?? '—' }}</td>
        </tr>
    </table>

    @if ($invoice->ship_date || $invoice->ship_via || $invoice->fob || $invoice->tracking_no)
        <table class="meta full" style="margin-top: 8px;">
            <tr>
                <th>{{ __('Ship Date') }}</th>
                <th>{{ __('Ship Via') }}</th>
                <th>{{ __('F.O.B.') }}</th>
                <th>{{ __('Tracking #') }}</th>
            </tr>
            <tr>
                <td>{{ $invoice->ship_date?->format('n/j/Y') ?? '—' }}</td>
                <td>{{ $invoice->ship_via ?: '—' }}</td>
                <td>{{ $invoice->fob ?: '—' }}</td>
                <td>{{ $invoice->tracking_no ?: '—' }}</td>
            </tr>
        </table>
    @endif

    <table class="lines">
        <thead>
            <tr>
                @if ($settings->show_item_column) <th style="width: 18%;">{{ __('Item') }}</th> @endif
                <th>{{ __('Description') }}</th>
                @if ($settings->show_qty_column) <th class="num" style="width: 8%;">{{ __('Qty') }}</th> @endif
                @if ($settings->show_tax_column) <th style="width: 8%;">{{ __('Tax') }}</th> @endif
                @if ($settings->show_unit_column) <th class="num" style="width: 14%;">{{ __('Price Each') }}</th> @endif
                <th class="num" style="width: 14%;">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                {{-- The amount guard keeps any line that carries a value visible, so the
                     printed rows still visibly sum to the stored invoice totals. --}}
                @if ($settings->hide_zero_qty_lines && (float) $line->quantity == 0.0 && (int) $line->line_subtotal_cents === 0)
                    @continue
                @endif
                <tr>
                    @if ($settings->show_item_column)
                        <td>{{ optional($line->item)->name }}</td>
                    @endif
                    <td>
                        {!! \App\Support\Text\LineDescription::toHtml($line->description) !!}
                        @if ($settings->show_service_date_column && $line->service_date)
                            <div class="muted" style="font-size: 9px;">{{ __('Service date') }}: {{ $line->service_date->format('n/j/Y') }}</div>
                        @endif
                    </td>
                    @if ($settings->show_qty_column)
                        <td class="num">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                    @endif
                    @if ($settings->show_tax_column)
                        <td>{{ optional($line->taxCode)->code }}</td>
                    @endif
                    @if ($settings->show_unit_column)
                        <td class="num">
                            {{ number_format($line->unit_price_cents / 100, 2) }}
                            @if ($line->line_discount_cents)
                                <div class="muted" style="font-size: 9px;">{{ __('less :amt disc', ['amt' => number_format($line->line_discount_cents / 100, 2)]) }}</div>
                            @endif
                        </td>
                    @endif
                    <td class="num">{{ number_format($line->line_subtotal_cents / 100, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>{{ __('Subtotal') }}</td>
            <td class="num">{{ number_format($invoice->subtotal_cents / 100, 2) }}</td>
        </tr>
        @foreach ($taxSummary as $tax)
            <tr>
                <td>{{ $tax['label'] }} {{ number_format($tax['rate'], 2) }}%</td>
                <td class="num">{{ number_format($tax['tax_cents'] / 100, 2) }}</td>
            </tr>
        @endforeach
        <tr class="grand">
            <td>{{ __('Total') }}</td>
            <td class="num">${{ number_format($invoice->total_cents / 100, 2) }}</td>
        </tr>
        @if ($invoice->amount_paid_cents > 0)
            <tr>
                <td>{{ __('Paid') }}</td>
                <td class="num">{{ number_format($invoice->amount_paid_cents / 100, 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('Balance Due') }}</td>
                <td class="num">${{ number_format($invoice->balanceCents() / 100, 2) }}</td>
            </tr>
        @endif
    </table>

    @php($schedule = $invoice->paymentRequests->isNotEmpty() ? app(\App\Services\Sales\PaymentRequestScheduleStatus::class)->for($invoice) : collect())
    @if ($settings->show_payment_schedule && $schedule->isNotEmpty())
        <table class="data" style="margin-top: 16px;">
            <thead>
                <tr>
                    <th colspan="2">{{ __('Payment Schedule') }}</th>
                    <th class="num">{{ __('Amount') }}</th>
                    <th class="num">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($schedule as $row)
                    <tr>
                        <td>{{ $row['request']->label }}</td>
                        <td>{{ $row['request']->due_date?->toDateString() }}</td>
                        <td class="num">{{ number_format($row['request']->amount_cents / 100, 2) }}</td>
                        <td class="num">{{ $row['status']->label() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        @if ($settings->show_tax_number && filled($company->tax_number))
            <div class="taxno">{{ __('GST/HST No.') }} {{ $company->tax_number }}</div>
        @endif
        @if (filled($invoice->customer_message))
            <div class="message">{{ $invoice->customer_message }}</div>
        @endif
        @php($footerMessage = $style?->footer_message ?: $settings->footer_message)
        @if (filled($footerMessage))
            <div class="message">{{ $footerMessage }}</div>
        @endif
    </div>
</body>
</html>
