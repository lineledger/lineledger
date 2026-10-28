<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php use App\Support\Tax\LineTaxBreakdown; @endphp
    <title>{{ __('Invoice') }} {{ $invoice->invoice_no }} — {{ $company->name }}</title>
    {{--
        The "Airy" invoice layout: an open page with the sender top left and the
        logo top right, a large INVOICE heading, bill-to (and ship-to) beside the
        invoice number and dates, a shaded ACTIVITY / QTY / RATE / AMOUNT band,
        and the balance due under a dotted rule with payment instructions beside
        it. It prints everything the classic layout does — the same
        InvoiceSetting switches decide which company details and columns appear,
        and the payment schedule and registration numbers are the shared
        partials — which InvoiceLayoutParityTest checks.
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

        // The footer sits in the bottom page margin on every page, and the
        // margin grows with the message (about 100 characters a line) so a long
        // or multi-line footer never runs into the lines above it.
        $footerMessage = $style?->footer_message ?: $settings->footer_message;
        $footerHeight = filled($footerMessage)
            ? 13 * collect(preg_split('/\R/u', trim($footerMessage)))->sum(fn (string $line) => max(1, (int) ceil(mb_strlen($line) / 100)))
            : 0;
    @endphp
    <style>
        @page { margin: 40px 44px {{ 44 + $footerHeight }}px 44px; }
        {{-- DejaVu Sans, like every other document: dompdf's built-in Helvetica
             only covers Windows-1252, so ₹, ₩, Polish or Cyrillic names would
             print as "?". --}}
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; line-height: 1.3; }
        table { border-collapse: collapse; }
        .full { width: 100%; }
        .top td { vertical-align: top; padding: 0; }
        .from div { margin-bottom: 2px; font-size: 12px; }
        .from .company-name { font-weight: bold; }
        .from .muted { color: #111827; }
        .logo { max-width: 200px; }
        h1.title { font-size: 34px; font-weight: normal; margin: 14px 0 12px 2px; letter-spacing: 0.01em; @if ($accent) color: {{ $accent }}; @endif }
        .label { font-weight: bold; text-transform: uppercase; font-size: 11px; letter-spacing: 0.02em; }
        .party div { margin-bottom: 2px; }
        table.meta { margin-left: auto; }
        table.meta td { padding: 0 0 2px 0; }
        table.meta td.k { text-align: right; padding-right: 6px; }
        table.shipping { margin-top: 12px; }
        table.shipping td { padding: 0 24px 1px 0; }
        .rule { border-top: 1px solid #111827; margin: 16px 0 30px 0; }
        table.lines { width: 100%; }
        table.lines thead th { background: {{ $band }}; font-weight: normal; font-size: 11px; text-transform: uppercase; padding: 5px 6px; text-align: left; }
        table.lines td { padding: 6px 6px 2px 6px; vertical-align: top; }
        table.lines .num { text-align: right; white-space: nowrap; }
        table.lines .item { font-weight: bold; }
        .dotted { border-top: 1px dotted #6b7280; margin: 14px 0 10px 0; }
        table.bottom td { vertical-align: top; padding: 0; }
        .message { font-size: 10.5px; white-space: pre-line; }
        .payment { font-size: 10.5px; white-space: pre-line; margin-top: 14px; }
        table.totals { width: 100%; }
        table.totals td { padding: 1px 0 3px 0; }
        table.totals td.k { padding-right: 12px; text-transform: uppercase; }
        table.totals td.v { text-align: right; white-space: nowrap; }
        table.totals tr.due td.v { font-size: 20px; font-weight: bold; }
        table.data { width: 100%; }
        table.data th { background: {{ $band }}; font-weight: normal; font-size: 11px; text-transform: uppercase; padding: 4px 6px; text-align: left; }
        table.data td { padding: 4px 6px 2px 6px; }
        table.data .num { text-align: right; white-space: nowrap; }
        .regs { font-size: 9.5px; margin-top: 10px; }
        .footer { position: fixed; bottom: -{{ $footerHeight + 6 }}px; left: 0; right: 0; height: {{ $footerHeight }}px; text-align: center; font-size: 10px; line-height: 13px; white-space: pre-line; }
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

        // Ship to only when the customer has a shipping address, so a services
        // invoice keeps the open two-column header.
        $shipLines = collect([
            $contact?->shipping_line1,
            $contact?->shipping_line2,
            \App\Support\Contacts\AddressLines::locality($contact?->shipping_city, $contact?->shipping_region, $contact?->shipping_postal_code, $contact?->shipping_country, $company),
        ])->filter()->values();

        // Printed whenever the invoice has them. The show_terms / show_ship_date
        // … settings only choose which fields the entry form offers.
        $shipping = array_filter([
            __('Ship Date') => $invoice->ship_date ? \App\Support\Locales::formatDate($invoice->ship_date, $dateFormat) : null,
            __('Ship Via') => $invoice->ship_via,
            __('F.O.B.') => $invoice->fob,
            __('Tracking #') => $invoice->tracking_no,
        ], fn ($value) => filled($value));

        $showItem = (bool) $settings->show_item_column;
        $showQty = (bool) $settings->show_qty_column;
        $showRate = (bool) $settings->show_unit_column;
        $showTax = (bool) $settings->show_tax_column;

        $hasTax = collect($taxSummary)->sum('tax_cents') !== 0;
        $hasDiscount = (int) ($invoice->document_discount_cents ?? 0) !== 0;
        $paid = (int) $invoice->amount_paid_cents;
        $credits = (int) $invoice->reconciled_cents;
        $logo = ($style?->show_logo ?? $settings->show_logo) ? $company->documentLogoDataUri() : null;
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
            <td class="party" style="width: {{ $shipLines->isNotEmpty() ? '32%' : '55%' }};">
                <div class="label">{{ __('Invoice to') }}</div>
                @foreach ($billLines as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </td>
            @if ($shipLines->isNotEmpty())
                <td class="party" style="width: 28%; padding-right: 12px;">
                    <div class="label">{{ __('Ship To') }}</div>
                    @foreach ($shipLines as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                </td>
            @endif
            <td style="width: {{ $shipLines->isNotEmpty() ? '40%' : '45%' }};">
                <table class="meta">
                    <tr><td class="k label">{{ __('Invoice no.') }}</td><td>{{ $invoice->invoice_no }}</td></tr>
                    <tr><td class="k label">{{ __('Date') }}</td><td>{{ \App\Support\Locales::formatDate($invoice->invoice_date, $dateFormat) }}</td></tr>
                    @if ($invoice->due_date)
                        <tr><td class="k label">{{ __('Due date') }}</td><td>{{ \App\Support\Locales::formatDate($invoice->due_date, $dateFormat) }}</td></tr>
                    @endif
                    @if ($invoice->terms)
                        <tr><td class="k label">{{ __('Terms') }}</td><td>{{ $invoice->terms->name }}</td></tr>
                    @endif
                    @if (filled($invoice->customer_po))
                        <tr><td class="k label">{{ __('P.O. #') }}</td><td>{{ $invoice->customer_po }}</td></tr>
                    @endif
                    @if ($invoice->salesRep)
                        <tr><td class="k label">{{ __('Rep') }}</td><td>{{ $invoice->salesRep->display_name }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @if ($shipping !== [])
        <table class="shipping">
            <tr>
                @foreach ($shipping as $label => $value)
                    <td class="label">{{ $label }}</td>
                @endforeach
            </tr>
            <tr>
                @foreach ($shipping as $value)
                    <td>{{ $value }}</td>
                @endforeach
            </tr>
        </table>
    @endif

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
                        @if ($showItem && $line->item)
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
                    @include('pdf.invoices.partials.tax-numbers')
                </div>
            </td>
            <td style="width: 50%;">
                {{-- The breakdown only when there is something between the lines
                     and the balance — tax, a discount, a payment or a credit — so
                     a plain invoice shows just the balance due. --}}
                <table class="totals">
                    @if ($hasTax || $hasDiscount)
                        <tr><td class="k">{{ __('Subtotal') }}</td><td class="v">{{ $money((int) $invoice->subtotal_cents) }}</td></tr>
                        @if ($hasDiscount)
                            <tr><td class="k">{{ __('Discount') }}</td><td class="v">-{{ $money((int) $invoice->document_discount_cents) }}</td></tr>
                        @endif
                        @foreach ($taxSummary as $tax)
                            <tr><td class="k">{{ $tax['label'] }}@if (! str_contains($tax['label'], '%')) {{ LineTaxBreakdown::formatRate($tax['rate']) }}%@endif</td><td class="v">{{ $money((int) $tax['tax_cents']) }}</td></tr>
                        @endforeach
                    @endif
                    @if ($hasTax || $hasDiscount || $paid > 0 || $credits > 0)
                        <tr><td class="k">{{ __('Total') }}</td><td class="v">{{ $money((int) $invoice->total_cents) }}</td></tr>
                    @endif
                    @if ($paid > 0)
                        <tr><td class="k">{{ __('Payment') }}</td><td class="v">-{{ $money($paid) }}</td></tr>
                    @endif
                    @if ($credits > 0)
                        <tr><td class="k">{{ __('Credits applied') }}</td><td class="v">-{{ $money($credits) }}</td></tr>
                    @endif
                    <tr class="due">
                        <td class="k">{{ __('Balance Due') }}</td>
                        <td class="v">{{ $currencySymbol }}{{ $money((int) $invoice->balanceCents()) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @include('pdf.invoices.partials.payment-schedule')

    @if (filled($footerMessage))
        <div class="footer">{{ $footerMessage }}</div>
    @endif
</body>
</html>
