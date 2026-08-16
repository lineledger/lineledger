<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 32px 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        .heading { font-size: 16px; font-weight: bold; text-align: center; margin-bottom: 4px; }
        .charity { text-align: center; margin-bottom: 16px; }
        .row { margin-bottom: 4px; }
        .label { color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        td { padding: 4px 6px; border-bottom: 1px solid #ddd; }
        .num { text-align: right; }
        .eligible { font-weight: bold; font-size: 13px; }
        .footer { margin-top: 24px; font-size: 10px; color: #555; }
        .sig { margin-top: 28px; border-top: 1px solid #333; width: 220px; padding-top: 4px; }
        .watermark { color: #b00; font-weight: bold; font-size: 14px; text-align: center; margin-bottom: 8px; }
    </style>
</head>
<body>
    @if ($receipt->status === \App\Enums\DonationReceiptStatus::Void)
        <div class="watermark">CANCELLED</div>
    @endif

    <div class="heading">Official receipt for income tax purposes</div>

    <div class="charity">
        <strong>{{ $company->legal_name ?: $company->name }}</strong><br>
        @if ($company->address_line1){{ $company->address_line1 }}, @endif{{ $company->address_city }} {{ $company->address_region }} {{ $company->address_postal_code }}<br>
        Charity registration number: <strong>{{ $charityNumber }}</strong>
    </div>

    <div class="row"><span class="label">Receipt number:</span> {{ $receipt->receipt_no }}</div>
    <div class="row"><span class="label">Date receipt issued:</span> {{ $receipt->issued_date?->toDateString() ?? '—' }}</div>
    @if ($receipt->is_consolidated)
        <div class="row"><span class="label">Gifts received in:</span> {{ $receipt->consolidation_year }}</div>
    @else
        <div class="row"><span class="label">Date gift received:</span> {{ $receipt->gift_date->toDateString() }}</div>
    @endif

    <div class="row" style="margin-top: 10px;">
        <span class="label">Donated by:</span> {{ $receipt->donor_name }}<br>
        @if ($receipt->donor_line1){{ $receipt->donor_line1 }}<br>@endif
        {{ $receipt->donor_city }} {{ $receipt->donor_region }} {{ $receipt->donor_postal_code }}
    </div>

    <table>
        <tr>
            <td>Amount received (fair market value of the gift)</td>
            <td class="num">${{ number_format($receipt->amount_cents / 100, 2) }}</td>
        </tr>
        @if ($receipt->advantage_cents > 0)
            <tr>
                <td>Value of advantage @if ($receipt->advantage_description)({{ $receipt->advantage_description }})@endif</td>
                <td class="num">${{ number_format($receipt->advantage_cents / 100, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td class="eligible">Eligible amount of gift for tax purposes</td>
            <td class="num eligible">${{ number_format($receipt->eligible_amount_cents / 100, 2) }}</td>
        </tr>
    </table>

    @if ($receipt->gift_type === \App\Enums\GiftType::InKind)
        <div class="row" style="margin-top: 10px;">
            <span class="label">Description of property received (non-cash gift):</span> {{ $receipt->in_kind_description }}
            @if ($receipt->appraised_by)
                <br><span class="label">Appraised by:</span> {{ $receipt->appraised_by }}@if ($receipt->appraisal_date) on {{ $receipt->appraisal_date->toDateString() }}@endif
            @endif
        </div>
    @endif

    <div class="footer">
        For information on registered charities, visit the Canada Revenue Agency at www.canada.ca/charities-giving.
    </div>

    <div class="sig">Authorized signature</div>
</body>
</html>
