<?php

namespace App\Actions\Portal;

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Sales\SaveReceipt;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Notifications\Portal\PortalPaymentReceiptNotification;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\ReceiptPoster;
use Illuminate\Support\Facades\DB;

/**
 * Records a settled Stripe payment as a posted customer receipt plus a separate
 * processing-fee journal entry, reusing the standard accounting pipeline so the
 * GL stays correct:
 *   - Receipt: DR Stripe Clearing / CR AR, full invoice amount, applied FIFO
 *     across the paid invoices (sets amount_paid_cents + invoice status).
 *   - Fee JE: DR Merchant Processing Fees / CR Stripe Clearing.
 *
 * Idempotent on the PaymentIntent id, so a webhook replay is a no-op. The caller
 * (webhook) must have bound the company as current_company.
 */
final class RecordStripePayment
{
    public function __construct(
        protected EnsureStripeAccounts $accounts,
        protected SaveReceipt $saveReceipt,
        protected ReceiptPoster $receiptPoster,
        protected SaveJournalEntry $saveJournalEntry,
        protected JournalPoster $journalPoster,
    ) {}

    /**
     * @param  array<int, int>  $invoiceIds  Invoices the payment covers, in apply order.
     */
    public function handle(Company $company, string $paymentIntentId, int $amountCents, int $feeCents, int $contactId, array $invoiceIds): CustomerReceipt
    {
        $existing = CustomerReceipt::withTrashed()
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $ledger = $this->accounts->handle($company);

        return DB::transaction(function () use ($company, $paymentIntentId, $amountCents, $feeCents, $contactId, $invoiceIds, $ledger): CustomerReceipt {
            $receipt = $this->saveReceipt->handle([
                'contact_id' => $contactId,
                'receipt_date' => $company->currentDateTime()->toDateString(),
                'deposit_to_account_id' => $ledger['clearing']->id,
                'payment_method_id' => $ledger['method']->id,
                'reference' => $paymentIntentId,
                'amount_cents' => $amountCents,
                'memo' => __('Online card payment'),
                'applications' => $this->allocate($contactId, $invoiceIds, $amountCents),
            ]);

            $receipt->update([
                'stripe_payment_intent_id' => $paymentIntentId,
                'stripe_fee_cents' => $feeCents,
            ]);

            $this->receiptPoster->post($receipt->fresh('applications'));

            if ($feeCents > 0) {
                $this->postFeeEntry($company, $ledger, $feeCents, $paymentIntentId);
            }

            $receipt->contact->notify(new PortalPaymentReceiptNotification($receipt->fresh(), $company));

            return $receipt->fresh();
        });
    }

    /**
     * Tile the received amount across the customer's named open invoices, oldest
     * first, capping each at its current balance.
     *
     * @param  array<int, int>  $invoiceIds
     * @return array<int, array{invoice_id: int, amount_cents: int}>
     */
    private function allocate(int $contactId, array $invoiceIds, int $amountCents): array
    {
        $invoices = Invoice::query()
            ->where('contact_id', $contactId)
            ->whereIn('id', $invoiceIds)
            ->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value])
            ->orderBy('due_date')
            ->orderBy('invoice_date')
            ->get();

        $remaining = $amountCents;
        $applications = [];

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) {
                break;
            }

            $apply = min($remaining, $invoice->balanceCents());

            if ($apply <= 0) {
                continue;
            }

            $applications[] = ['invoice_id' => $invoice->id, 'amount_cents' => $apply];
            $remaining -= $apply;
        }

        return $applications;
    }

    /**
     * @param  array{clearing: Account, fees: Account, method: PaymentMethod}  $ledger
     */
    private function postFeeEntry(Company $company, array $ledger, int $feeCents, string $paymentIntentId): void
    {
        $entry = $this->saveJournalEntry->handle([
            'entry_date' => $company->currentDateTime()->toDateString(),
            'memo' => __('Stripe processing fee (:ref)', ['ref' => $paymentIntentId]),
            'lines' => [
                ['account_id' => $ledger['fees']->id, 'debit_cents' => $feeCents, 'credit_cents' => 0],
                ['account_id' => $ledger['clearing']->id, 'debit_cents' => 0, 'credit_cents' => $feeCents],
            ],
        ]);

        $this->journalPoster->post($entry);
    }
}
