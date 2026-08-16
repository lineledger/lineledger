<?php

namespace App\Actions\Sales;

use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentRequestType;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Invoice;
use App\Models\InvoicePaymentRequest;
use Illuminate\Support\Facades\DB;

/**
 * Replaces an invoice's milestone payment-request schedule. Resolves each row to
 * integer cents (percentages off the invoice total, with any rounding remainder
 * folded into the last row when the schedule sums to the whole invoice), and
 * rejects a schedule that asks for more than the invoice is worth. Writes no
 * general-ledger entries — milestones are a tracking layer over the single AR
 * balance, so they never fragment AR.
 *
 * Each $requests row:
 *   label:    string
 *   type:     'percent'|'fixed'
 *   percent:  float|string   (percent rows)
 *   amount_cents: int        (fixed rows)
 *   due_date: ?string
 *   status:   ?'requested'|'cancelled'
 */
final class SaveInvoicePaymentRequests
{
    /**
     * @param  array<int, array<string, mixed>>  $requests
     * @return list<InvoicePaymentRequest>
     */
    public function handle(Invoice $invoice, array $requests): array
    {
        $rows = $this->resolve($invoice, array_values($requests));

        return DB::transaction(function () use ($invoice, $rows): array {
            $invoice->paymentRequests()->delete();

            $saved = [];
            foreach ($rows as $index => $row) {
                $saved[] = $invoice->paymentRequests()->create([
                    'company_id' => $invoice->company_id,
                    'label' => $row['label'],
                    'request_type' => $row['type'],
                    'percent' => $row['percent'],
                    'amount_cents' => $row['amount_cents'],
                    'due_date' => $row['due_date'],
                    'status' => $row['status'],
                    'sort_order' => $index,
                ]);
            }

            return $saved;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $requests
     * @return list<array{label: string, type: PaymentRequestType, percent: ?float, amount_cents: int, due_date: ?string, status: PaymentRequestStatus}>
     */
    private function resolve(Invoice $invoice, array $requests): array
    {
        $total = (int) $invoice->total_cents;
        $rows = [];
        $sum = 0;
        $percentSum = 0.0;

        foreach ($requests as $request) {
            $type = PaymentRequestType::from($request['type']);

            if ($type === PaymentRequestType::Percent) {
                $percent = (float) $request['percent'];
                if ($percent < 0 || $percent > 100) {
                    throw new PostingValidationException(__('A milestone percentage must be between 0 and 100.'));
                }
                $percentSum += $percent;
                $cents = (int) round($total * $percent / 100);
            } else {
                $cents = (int) $request['amount_cents'];
                $percent = null;
            }

            if ($cents < 0) {
                throw new PostingValidationException(__('A milestone amount cannot be negative.'));
            }

            $sum += $cents;
            $rows[] = [
                'label' => (string) $request['label'],
                'type' => $type,
                'percent' => $percent,
                'amount_cents' => $cents,
                'due_date' => $request['due_date'] ?? null,
                'status' => isset($request['status'])
                    ? PaymentRequestStatus::from($request['status'])
                    : PaymentRequestStatus::Requested,
            ];
        }

        // When the schedule covers the whole invoice (100% / exact total), fold any
        // rounding remainder into the last row so the milestones foot to the total.
        if ($rows !== [] && ($percentSum === 100.0 || $sum === $total) && $sum !== $total) {
            $last = count($rows) - 1;
            $rows[$last]['amount_cents'] += $total - $sum;
            $sum = $total;
        }

        if ($sum > $total) {
            throw new PostingValidationException(__('Milestone payment requests cannot exceed the invoice total.'));
        }

        return $rows;
    }
}
