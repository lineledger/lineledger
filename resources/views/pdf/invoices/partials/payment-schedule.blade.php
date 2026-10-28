{{-- The invoice's payment requests (deposit, instalments) and where each
     stands, when the invoice settings print the schedule. Every invoice layout
     includes this. --}}
@php
    $schedule = $invoice->paymentRequests->isNotEmpty() ? app(\App\Services\Sales\PaymentRequestScheduleStatus::class)->for($invoice) : collect();
@endphp
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
