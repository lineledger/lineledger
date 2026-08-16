<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $documentTitle = $isReimbursement ? __('Reimbursement') : __('Bill');
        $numberLabel = $isReimbursement ? __('Reimbursement #') : __('Bill #');
        $partyLabel = $isReimbursement ? __('Pay To') : __('Vendor');
    @endphp
    <title>{{ $documentTitle }} {{ $bill->bill_no }} — {{ $company->name }}</title>
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
        $partyLines = collect([
            $bill->contact?->display_name,
            $bill->contact?->billing_line1,
            $bill->contact?->billing_line2,
            collect([$bill->contact?->billing_city, $bill->contact?->billing_region, $bill->contact?->billing_postal_code])->filter()->implode(', '),
        ])->filter()->values();

        $columns = 3
            + ($settings->show_qty_column ? 1 : 0)
            + ($settings->show_item_column ? 1 : 0)
            + ($settings->show_tax_column ? 1 : 0);
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
                <h1 class="title">{{ Str::upper($documentTitle) }}</h1>
                <table class="meta">
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ $numberLabel }}</th>
                    </tr>
                    <tr>
                        <td>{{ $bill->bill_date?->format('n/j/Y') }}</td>
                        <td>{{ $bill->bill_no }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="full parties">
        <tr>
            <td style="width: 48%;">
                <div class="box">
                    <div class="box-label">{{ $partyLabel }}</div>
                    @foreach ($partyLines as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                </div>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%;">
                @unless ($isReimbursement)
                    <table class="meta">
                        <tr>
                            <th>{{ __('Terms') }}</th>
                            <th>{{ __('Due Date') }}</th>
                        </tr>
                        <tr>
                            <td>{{ optional($bill->terms)->name ?? '—' }}</td>
                            <td>{{ $bill->due_date?->format('n/j/Y') ?? '—' }}</td>
                        </tr>
                    </table>
                    @if (filled($bill->vendor_reference))
                        <table class="meta" style="margin-top: 8px;">
                            <tr><th>{{ __('Reference') }}</th></tr>
                            <tr><td>{{ $bill->vendor_reference }}</td></tr>
                        </table>
                    @endif
                @endunless
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                @if ($settings->show_qty_column) <th class="num" style="width: 8%;">{{ __('Qty') }}</th> @endif
                @if ($settings->show_item_column) <th style="width: 18%;">{{ __('Item') }}</th> @endif
                <th>{{ __('Description') }}</th>
                @if ($settings->show_tax_column) <th style="width: 8%;">{{ __('Tax') }}</th> @endif
                <th class="num" style="width: 14%;">{{ __('Price Each') }}</th>
                <th class="num" style="width: 14%;">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bill->lines as $line)
                <tr>
                    @if ($settings->show_qty_column)
                        <td class="num">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                    @endif
                    @if ($settings->show_item_column)
                        <td>{{ optional($line->item)->name }}</td>
                    @endif
                    <td>{{ $line->description }}</td>
                    @if ($settings->show_tax_column)
                        <td>{{ optional($line->taxCode)->code }}</td>
                    @endif
                    <td class="num">{{ number_format($line->unit_price_cents / 100, 2) }}</td>
                    <td class="num">{{ number_format($line->line_subtotal_cents / 100, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>{{ __('Subtotal') }}</td>
            <td class="num">{{ number_format($bill->subtotal_cents / 100, 2) }}</td>
        </tr>
        @foreach ($taxSummary as $tax)
            <tr>
                <td>{{ $tax['label'] }} {{ number_format($tax['rate'], 2) }}%</td>
                <td class="num">{{ number_format($tax['tax_cents'] / 100, 2) }}</td>
            </tr>
        @endforeach
        <tr class="grand">
            <td>{{ __('Total') }}</td>
            <td class="num">${{ number_format($bill->total_cents / 100, 2) }}</td>
        </tr>
        @if ($bill->amount_paid_cents > 0)
            <tr>
                <td>{{ __('Paid') }}</td>
                <td class="num">{{ number_format($bill->amount_paid_cents / 100, 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('Balance Due') }}</td>
                <td class="num">${{ number_format($bill->balanceCents() / 100, 2) }}</td>
            </tr>
        @endif
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
