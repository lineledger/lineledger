<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php use App\Support\Tax\LineTaxBreakdown; @endphp
    <title>{{ __('Invoice') }} {{ $invoice->invoice_no }} — {{ $company->name }}</title>
    {{--
        The "Airy" invoice layout: an open page with the sender top left and the
        logo top right, a large INVOICE heading, bill-to beside the invoice
        number and dates, a shaded ACTIVITY / QTY / RATE / AMOUNT band, and the
        balance due under a dotted rule with payment instructions beside it.
        The same InvoiceSetting switches as the classic layout decide which
        company details and columns appear.
    --}}
    @php
        $style = $style ?? null;
        $accent = $style?->accent_color;
        $band = $accent ? sprintf(
            '#%02x%02x%02x',
            (int) round(hexdec(substr($accent, 1, 2)) * 0.25 + 255 * 0.75),
            (int) round(hexdec(substr($accent, 3, 2)) * 0.25 + 255 * 0.75),
            (int) round(hexdec(substr($accent, 5, 2)) * 0.25 + 255 * 0.75),
        ) : '#d4d4d4';
    @endphp
    <style>
        @page { margin: 40px 44px 52px 44px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 13px; color: #111827; line-height: 1.3; }
        table { border-collapse: collapse; }
        .full { width: 100%; }
        .top td { vertical-align: top; padding: 0; }
        .from div { margin-bottom: 2px; font-size: 13px; }
        .from .company-name { font-weight: bold; }
        .from .muted { color: #111827; }
        .logo { max-width: 200px; }
        h1.title { font-size: 34px; font-weight: normal; margin: 14px 0 12px 2px; letter-spacing: 0.01em; @if ($accent) color: {{ $accent }}; @endif }
        .label { font-weight: bold; text-transform: uppercase; font-size: 12.5px; letter-spacing: 0.02em; }
        .billto div { margin-bottom: 2px; }
        table.meta { margin-left: auto; }
        table.meta td { padding: 0 0 2px 0; }
        table.meta td.k { text-align: right; padding-right: 6px; }
        .rule { border-top: 1px solid #111827; margin: 16px 0 34px 0; }
        table.lines { width: 100%; }
        table.lines thead th { background: {{ $band }}; font-weight: normal; font-size: 12px; text-transform: uppercase; padding: 5px 6px; text-align: left; }
        table.lines td { padding: 6px 6px 2px 6px; vertical-align: top; }
        table.lines .num { text-align: right; white-space: nowrap; }
        table.lines .item { font-weight: bold; }
        .dotted { border-top: 1px dotted #6b7280; margin: 14px 0 10px 0; }
        table.bottom td { vertical-align: top; padding: 0; }
        .message { font-size: 11px; white-space: pre-line; }
        .payment { font-size: 11px; white-space: pre-line; margin-top: 14px; }
        table.totals { width: 100%; }
        table.totals td { padding: 1px 0 3px 0; }
        table.totals td.k { padding-right: 12px; }
        table.totals td.v { text-align: right; white-space: nowrap; }
        table.totals tr.due td.k { padding-top: 0; }
        table.totals tr.due td.v { font-size: 22px; font-weight: bold; }
        .regs { font-size: 10px; margin-top: 10px; }
        .footer { position: fixed; bottom: -28px; left: 0; right: 0; text-align: center; font-size: 11px; }
    </style>
</head>
<body>
    @php
        $currencySymbol = \App\Support\Currency::symbol($invoice->currency_code ?: $company->currency_code);
        $dateFormat = $company->jurisdiction->shortDateFormat();
        $money = fn (int $cents) => number_format($cents / 100, 2);
        $contact = $invoice->contact;

        // Bill to: the person, then the organisation (when it is a different
        // name), then the billing address.
        $person = trim(($contact?->first_name ?? '').' '.($contact?->last_name ?? ''));
        $organisation = $contact?->company_name ?: $contact?->display_name;
        $billLines = collect([
            $person !== '' ? $person : $contact?->display_name,
            $person !== '' && $organisation && mb_strtolower($organisation) !== mb_strtolower($person) ? $organisation : null,
            $contact?->billing_line1,
            $contact?->billing_line2,
            \App\Support\Contacts\AddressLines::locality($contact?->billing_city, $contact?->billing_region, $contact?->billing_postal_code, $contact?->billing_country, $company),
        ])->filter()->unique()->values();

        $showQty = (bool) $settings->show_qty_column;
        $showRate = (bool) $settings->show_unit_column;
        $showTax = (bool) $settings->show_tax_column;

        $hasTax = collect($taxSummary)->sum('tax_cents') !== 0;
        $hasDiscount = (int) ($invoice->document_discount_cents ?? 0) !== 0;
        $paid = (int) $invoice->amount_paid_cents;
        $logo = ($style?->show_logo ?? $settings->show_logo) ? $company->documentLogoDataUri() : null;
        $footerMessage = $style?->footer_message ?: $settings->footer_message;
        // Whole quantities print bare (1), fractional ones to at least two
        // places (7.50, 0.125), the way QuickBooks prints them.
        $qty = function ($q): string {
            $q = (float) $q;
            if (floor($q) === $q) {
                return number_format($q, 0, '.', ',');
            }
            $text = rtrim(number_format($q, 4, '.', ','), '0');

            return strlen(substr(strrchr($text, '.'), 1)) < 2 ? number_format($q, 2, '.', ',') : $text;
        };
    @endphp

    <table class="full top">
        <tr>
            <td class="from" style="width: 60%;">
                @include('pdf.partials._company-header', [
                    'company' => $company,
                    'settings' => $settings,
                    'documentLogo' => null,
                    'logoMaxHeight' => 0,
                ])
            </td>
            <td style="width: 40%; text-align: right;">
                @if ($logo)
                    <img src="{{ $logo }}" class="logo" style="max-height: {{ $company->documentLogoMaxHeight() }}px;" alt="{{ $company->name }}">
                @endif
            </td>
        </tr>
    </table>

    <h1 class="title">{{ __('INVOICE') }}</h1>

    <table class="full top">
        <tr>
            <td class="billto" style="width: 55%;">
                <div class="label">{{ __('Invoice to') }}</div>
                @foreach ($billLines as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </td>
            <td style="width: 45%;">
                <table class="meta">
                    <tr><td class="k label">{{ __('Invoice no.') }}</td><td>{{ $invoice->invoice_no }}</td></tr>
                    <tr><td class="k label">{{ __('Date') }}</td><td>{{ \App\Support\Locales::formatDate($invoice->invoice_date, $dateFormat) }}</td></tr>
                    @if ($invoice->due_date)
                        <tr><td class="k label">{{ __('Due date') }}</td><td>{{ \App\Support\Locales::formatDate($invoice->due_date, $dateFormat) }}</td></tr>
                    @endif
                    @if ($settings->show_terms && $invoice->terms)
                        <tr><td class="k label">{{ __('Terms') }}</td><td>{{ $invoice->terms->name }}</td></tr>
                    @endif
                    @if ($settings->show_customer_po && filled($invoice->customer_po))
                        <tr><td class="k label">{{ __('P.O. #') }}</td><td>{{ $invoice->customer_po }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('Activity') }}</th>
                @if ($showTax) <th style="width: 10%;">{{ __('Tax') }}</th> @endif
                @if ($showQty) <th style="width: 19%;">{{ __('Qty') }}</th> @endif
                @if ($showRate) <th class="num" style="width: 15%;">{{ __('Rate') }}</th> @endif
                <th class="num" style="width: 16%;">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                @if ($settings->hide_zero_qty_lines && (float) $line->quantity == 0.0 && (int) $line->line_subtotal_cents === 0)
                    @continue
                @endif
                <tr>
                    <td>
                        @if ($line->item)
                            <div class="item">{{ $line->item->name }}</div>
                        @endif
                        @if (filled($line->description))
                            <div>{!! \App\Support\Text\LineDescription::toHtml($line->description) !!}</div>
                        @endif
                        @if ($settings->show_service_date_column && $line->service_date)
                            <div style="font-size: 9px;">{{ __('Service date') }}: {{ \App\Support\Locales::formatDate($line->service_date, $dateFormat) }}</div>
                        @endif
                    </td>
                    @if ($showTax) <td>{{ optional($line->taxCode)->label() }}</td> @endif
                    @if ($showQty) <td>{{ $qty($line->quantity) }}</td> @endif
                    @if ($showRate)
                        <td class="num">
                            {{ $money((int) $line->unit_price_cents) }}
                            @if ($line->line_discount_cents)
                                <div style="font-size: 9px;">{{ __('less :amt disc', ['amt' => $money((int) $line->line_discount_cents)]) }}</div>
                            @endif
                        </td>
                    @endif
                    <td class="num">{{ $money((int) $line->line_subtotal_cents) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="dotted"></div>

    <table class="full bottom">
        <tr>
            <td style="width: 50%; padding-right: 20px;">
                @if (filled($invoice->customer_message))
                    <div class="message">{{ $invoice->customer_message }}</div>
                @endif
                @if (filled($settings->payment_instructions))
                    <div class="payment">{{ $settings->payment_instructions }}</div>
                @endif
                <div class="regs">
                    @include('pdf.partials.registration-numbers')
                </div>
            </td>
            <td style="width: 50%;">
                <table class="totals">
                    @if ($hasTax || $hasDiscount)
                        <tr><td class="k">{{ __('SUBTOTAL') }}</td><td class="v">{{ $money((int) $invoice->subtotal_cents) }}</td></tr>
                        @if ($hasDiscount)
                            <tr><td class="k">{{ __('DISCOUNT') }}</td><td class="v">-{{ $money((int) $invoice->document_discount_cents) }}</td></tr>
                        @endif
                        @foreach ($taxSummary as $tax)
                            <tr><td class="k">{{ mb_strtoupper($tax['label']) }}@if (! str_contains($tax['label'], '%')) {{ LineTaxBreakdown::formatRate($tax['rate']) }}%@endif</td><td class="v">{{ $money((int) $tax['tax_cents']) }}</td></tr>
                        @endforeach
                        <tr><td class="k">{{ __('TOTAL') }}</td><td class="v">{{ $money((int) $invoice->total_cents) }}</td></tr>
                    @endif
                    @if ($paid > 0)
                        @if (! ($hasTax || $hasDiscount))
                            <tr><td class="k">{{ __('TOTAL') }}</td><td class="v">{{ $money((int) $invoice->total_cents) }}</td></tr>
                        @endif
                        <tr><td class="k">{{ __('PAYMENT') }}</td><td class="v">{{ $money($paid) }}</td></tr>
                    @endif
                    <tr class="due">
                        <td class="k">{{ __('BALANCE DUE') }}</td>
                        <td class="v">{{ $currencySymbol }}{{ $money((int) $invoice->balanceCents()) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if (filled($footerMessage))
        <div class="footer">{{ $footerMessage }}</div>
    @endif
</body>
</html>
