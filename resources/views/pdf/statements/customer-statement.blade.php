<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Statement') }} — {{ $contact->qualifiedName() }} — {{ $company->name }}</title>
    <style>
        @page { margin: 36px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        table { border-collapse: collapse; }
        .full { width: 100%; }
        .top td { vertical-align: top; padding: 0; border: none; }
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
        table.lines th, table.lines td { border: 1px solid #d1d5db; padding: 5px 8px; vertical-align: top; }
        table.lines td.memo { white-space: normal; word-wrap: break-word; overflow-wrap: break-word; }
        table.lines thead th { background: #f3f4f6; font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em; color: #374151; }
        table.lines tr.total td { border-top: 2px solid #9ca3af; font-size: 13px; font-weight: bold; }
        .num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        table.aging { width: 100%; margin-top: 18px; border: 1px solid #9ca3af; }
        table.aging th, table.aging td { border: 1px solid #9ca3af; padding: 4px 8px; text-align: right; font-size: 10px; font-family: DejaVu Sans Mono, monospace; }
        table.aging th { background: #f3f4f6; text-transform: uppercase; letter-spacing: 0.03em; text-align: center; font-family: DejaVu Sans, sans-serif; }
        table.aging td.total { font-weight: bold; }
        .empty { margin-top: 18px; padding: 12px; border: 1px solid #d1d5db; color: #4b5563; text-align: center; }
        .footer { margin-top: 28px; border-top: 1px solid #d1d5db; padding-top: 8px; color: #374151; }
        .footer .taxno { font-size: 10px; }
        .footer .message { margin-top: 6px; color: #4b5563; white-space: pre-line; }
    </style>
</head>
<body>
    @php
        $fmtDate = fn (?string $date): string => $date ? \Carbon\CarbonImmutable::parse($date)->format('n/j/Y') : '—';
        $money = fn (int $cents): string => number_format($cents / 100, 2);

        $statementForLines = collect([
            $contact->qualifiedName(),
            $contact->billing_line1,
            $contact->billing_line2,
            collect([$contact->billing_city, $contact->billing_region, $contact->billing_postal_code])->filter()->implode(', '),
        ])->filter()->values();

        $isOpenInvoices = $type === \App\Enums\CustomerStatementType::OpenInvoices;
        $aging = $data['aging'];
        $totalDue = $isOpenInvoices ? $data['total_due'] : $data['statement']['closing'];
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
                <h1 class="title">{{ __('STATEMENT') }}</h1>
                <table class="meta">
                    <tr>
                        <th>{{ __('Statement Date') }}</th>
                        <th>{{ $isOpenInvoices ? __('As Of') : __('Period') }}</th>
                    </tr>
                    <tr>
                        <td>{{ $fmtDate($isOpenInvoices ? $data['as_of'] : $data['end']) }}</td>
                        <td>
                            @if ($isOpenInvoices)
                                {{ $fmtDate($data['as_of']) }}
                            @else
                                {{ $fmtDate($data['start']) }} – {{ $fmtDate($data['end']) }}
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="full parties">
        <tr>
            <td style="width: 48%;">
                <div class="box">
                    <div class="box-label">{{ __('Statement For') }}</div>
                    @foreach ($statementForLines as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                </div>
            </td>
            <td style="width: 52%;"></td>
        </tr>
    </table>

    @if ($isOpenInvoices)
        @if ($data['rows'] === [])
            <div class="empty">{{ __('No open invoices — thank you, your account is up to date.') }}</div>
        @else
            <table class="lines">
                <thead>
                    <tr>
                        <th style="width: 11%;">{{ __('Date') }}</th>
                        <th style="width: 15%;">{{ __('Invoice #') }}</th>
                        <th>{{ __('Memo') }}</th>
                        <th style="width: 11%;">{{ __('Due Date') }}</th>
                        <th class="num" style="width: 17%;">{{ __('Original Amount') }}</th>
                        <th class="num" style="width: 14%;">{{ __('Balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['rows'] as $row)
                        @if ($row['kind'] === 'invoice')
                            <tr>
                                <td>{{ $fmtDate($row['invoice_date']) }}</td>
                                <td>{{ $row['invoice_no'] }}</td>
                                <td class="memo">{{ $row['memo'] }}</td>
                                <td>{{ $fmtDate($row['due_date']) }}</td>
                                <td class="num">{{ $money($row['total']) }}</td>
                                <td class="num">{{ $money($row['balance']) }}</td>
                            </tr>
                        @else
                            <tr>
                                <td colspan="4">{{ $row['label'] }}</td>
                                <td class="num"></td>
                                <td class="num">{{ $money($row['balance']) }}</td>
                            </tr>
                        @endif
                    @endforeach
                    <tr class="total">
                        <td colspan="5">{{ $totalDue < 0 ? __('Credit balance') : __('Total Due') }}</td>
                        <td class="num">${{ $money($totalDue) }}</td>
                    </tr>
                </tbody>
            </table>
        @endif
    @else
        <table class="lines">
            <thead>
                <tr>
                    <th style="width: 12%;">{{ __('Date') }}</th>
                    <th style="width: 13%;">{{ __('Type') }}</th>
                    <th style="width: 13%;">{{ __('Doc #') }}</th>
                    <th>{{ __('Memo') }}</th>
                    <th class="num" style="width: 13%;">{{ __('Charges') }}</th>
                    <th class="num" style="width: 13%;">{{ __('Payments') }}</th>
                    <th class="num" style="width: 13%;">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="6">{{ __('Opening balance') }}</td>
                    <td class="num">{{ $money($data['statement']['opening']) }}</td>
                </tr>
                @foreach ($data['statement']['lines'] as $line)
                    <tr>
                        <td>{{ $fmtDate($line['date']) }}</td>
                        <td>{{ $line['type'] }}</td>
                        <td>{{ $line['doc_no'] }}</td>
                        <td class="memo">{{ $line['memo'] }}</td>
                        <td class="num">{{ $line['debit'] !== 0 ? $money($line['debit']) : '' }}</td>
                        <td class="num">{{ $line['credit'] !== 0 ? $money($line['credit']) : '' }}</td>
                        <td class="num">{{ $money($line['running']) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="6">{{ $totalDue < 0 ? __('Credit balance') : __('Balance Due') }}</td>
                    <td class="num">${{ $money($data['statement']['closing']) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <table class="aging">
        <thead>
            <tr>
                <th>{{ __('Current') }}</th>
                <th>{{ __('1–30 Days') }}</th>
                <th>{{ __('31–60 Days') }}</th>
                <th>{{ __('61–90 Days') }}</th>
                <th>{{ __('90+ Days') }}</th>
                <th>{{ __('Total Due') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $money($aging['current']) }}</td>
                <td>{{ $money($aging['b1_30']) }}</td>
                <td>{{ $money($aging['b31_60']) }}</td>
                <td>{{ $money($aging['b61_90']) }}</td>
                <td>{{ $money($aging['b90_plus']) }}</td>
                <td class="total">{{ $money($aging['total']) }}</td>
            </tr>
        </tbody>
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
