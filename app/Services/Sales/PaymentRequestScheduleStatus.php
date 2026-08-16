<?php

namespace App\Services\Sales;

use App\Enums\PaymentRequestStatus;
use App\Models\Invoice;
use App\Models\InvoicePaymentRequest;
use Illuminate\Support\Collection;

/**
 * Derives each milestone's effective status from the invoice's cumulative
 * payments, in display order: milestones are marked Paid as the invoice's
 * settled amount covers their running total. Cancelled milestones keep their
 * stored status and don't consume payment. This keeps the schedule a pure view
 * over the single AR balance — no per-milestone ledger postings.
 */
class PaymentRequestScheduleStatus
{
    /**
     * @return Collection<int, array{request: InvoicePaymentRequest, status: PaymentRequestStatus}>
     */
    public function for(Invoice $invoice): Collection
    {
        $settled = $invoice->settledCents();
        $running = 0;

        return $invoice->paymentRequests->map(function (InvoicePaymentRequest $request) use (&$running, $settled): array {
            $cancelled = $request->status === PaymentRequestStatus::Cancelled;

            if (! $cancelled) {
                $running += (int) $request->amount_cents;
            }

            return ['request' => $request, 'status' => $this->effectiveStatus($cancelled, $running, $settled)];
        });
    }

    /**
     * A declared PaymentRequestStatus return type keeps the schedule collection's
     * value type consistent (avoiding a literal-union covariance mismatch).
     */
    private function effectiveStatus(bool $cancelled, int $running, int $settled): PaymentRequestStatus
    {
        if ($cancelled) {
            return PaymentRequestStatus::Cancelled;
        }

        return $running <= $settled ? PaymentRequestStatus::Paid : PaymentRequestStatus::Requested;
    }

    /**
     * The amount of the next still-outstanding milestone, for pre-filling the
     * portal pay form, or null when none remain.
     */
    public function nextDueAmountCents(Invoice $invoice): ?int
    {
        foreach ($this->for($invoice) as $row) {
            if ($row['status'] === PaymentRequestStatus::Requested) {
                return (int) $row['request']->amount_cents;
            }
        }

        return null;
    }
}
