<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Credit Memo') }} {{ $creditMemo->credit_memo_no }} — {{ $company->name }}</title>
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
        $billLines = collect([
            $creditMemo->contact?->display_name,
            $creditMemo->contact?->billing_line1,
            $creditMemo->contact?->billing_line2,
            collect([$creditMemo->contact?->billing_city, $creditMemo->contact?->billing_region, $creditMemo->contact?->billing_postal_code])->filter()->implode(', '),
        ])->filter()->values();

        $shipLines = collect([
            $creditMemo->contact?->shipping_line1,
            $creditMemo->contact?->shipping_line2,
            collect([$creditMemo->contact?->shipping_city, $creditMemo->contact?->shipping_region, $creditMemo->contact?->shipping_postal_code])->filter()->implode(', '),
        ])->filter()->values();

        // Description + Price Each + Amount always show; the rest are toggleable.
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
                <h1 class="title">{{ __('CREDIT MEMO') }}</h1>
                <table class="meta">
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Credit Memo #') }}</th>
                    </tr>
                    <tr>
                        <td>{{ $creditMemo->credit_memo_date?->format('n/j/Y') }}</td>
                        <td>{{ $creditMemo->credit_memo_no }}</td>
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

    @if ($creditMemo->salesRep)
        <table class="meta full" style="margin-top: 14px;">
            <tr>
                <th>{{ __('Rep') }}</th>
            </tr>
            <tr>
                <td>{{ $creditMemo->salesRep->display_name }}</td>
            </tr>
        </table>
    @endif

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
            @foreach ($creditMemo->lines as $line)
                <tr>
                    @if ($settings->show_qty_column)
                        <td class="num">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                    @endif
                    @if ($settings->show_item_column)
                        <td>{{ optional($line->item)->name }}</td>
                    @endif
                    <td>
                        {{ $line->description }}
                        @if ($settings->show_service_date_column && $line->service_date)
                            <div class="muted" style="font-size: 9px;">{{ __('Service date') }}: {{ $line->service_date->format('n/j/Y') }}</div>
                        @endif
                    </td>
                    @if ($settings->show_tax_column)
                        <td>{{ optional($line->taxCode)->code }}</td>
                    @endif
                    <td class="num">
                        {{ number_format($line->unit_price_cents / 100, 2) }}
                        @if ($line->line_discount_cents)
                            <div class="muted" style="font-size: 9px;">{{ __('less :amt disc', ['amt' => number_format($line->line_discount_cents / 100, 2)]) }}</div>
                        @endif
                    </td>
                    <td class="num">{{ number_format($line->line_subtotal_cents / 100, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>{{ __('Subtotal') }}</td>
            <td class="num">{{ number_format($creditMemo->subtotal_cents / 100, 2) }}</td>
        </tr>
        @foreach ($taxSummary as $tax)
            <tr>
                <td>{{ $tax['label'] }} {{ number_format($tax['rate'], 2) }}%</td>
                <td class="num">{{ number_format($tax['tax_cents'] / 100, 2) }}</td>
            </tr>
        @endforeach
        <tr class="grand">
            <td>{{ __('Total Credit') }}</td>
            <td class="num">${{ number_format($creditMemo->total_cents / 100, 2) }}</td>
        </tr>
    </table>

    <div class="footer">
        @if ($settings->show_tax_number && filled($company->tax_number))
            <div class="taxno">{{ __('GST/HST No.') }} {{ $company->tax_number }}</div>
        @endif
        @if (filled($creditMemo->customer_message))
            <div class="message">{{ $creditMemo->customer_message }}</div>
        @endif
        @if (filled($settings->footer_message))
            <div class="message">{{ $settings->footer_message }}</div>
        @endif
    </div>
</body>
</html>
