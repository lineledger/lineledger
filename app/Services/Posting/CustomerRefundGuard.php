<?php

namespace App\Services\Posting;

use App\Enums\ChequeStatus;
use App\Enums\DepositStatus;
use App\Enums\ReceiptStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Cheque;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Support\Money;

/**
 * Keeps a refund within the customer credit it pays back.
 *
 * A refund (a cheque, or a negative customer receipt for a card refund) pays
 * back the credit of a credit memo or of a payment's unapplied remainder. The
 * posters and SaveCheque call this on every write, so a refund that skips
 * RecordCustomerRefund — the API, a cheque edited after it was drafted, a
 * console script — still can't pay out more than its source holds.
 *
 * The source row is re-read under a lock, so two refunds of the same credit
 * serialize instead of both passing the ceiling check.
 */
class CustomerRefundGuard
{
    /**
     * Re-read the refund's source under a row lock; null when the refund has no
     * source or its source is gone.
     */
    public function lockSource(Cheque|CustomerReceipt $refund): CreditMemo|CustomerReceipt|null
    {
        if ($refund->credit_memo_id !== null) {
            return CreditMemo::query()->lockForUpdate()->find($refund->credit_memo_id);
        }

        if ($refund->refunded_receipt_id !== null) {
            return CustomerReceipt::query()->lockForUpdate()->find($refund->refunded_receipt_id);
        }

        return null;
    }

    /**
     * Refuse a refund its source can't cover. A no-op for a cheque or receipt
     * that isn't a refund.
     *
     * A credit memo source is checked only against its ceiling: books from
     * before the void guard can hold a voided memo with a refund against it,
     * and those must stay postable. A payment source must also be a posted,
     * home-currency payment of the same customer.
     */
    public function assertWithinSource(Cheque|CustomerReceipt $refund): void
    {
        if (! $refund->isRefund()) {
            return;
        }

        if ($this->isVoid($refund)) {
            return;
        }

        $source = $this->lockSource($refund);

        if ($source === null) {
            throw new PostingValidationException(__('The credit this refund pays back no longer exists.'));
        }

        if ($source instanceof CustomerReceipt) {
            $this->assertRefundablePayment($source, $refund);
        }

        $refundCents = $refund instanceof Cheque
            ? (int) $refund->amount_cents
            : -(int) $refund->amount_cents;

        $remaining = $source->refundableBaseCents() - $source->refundedCents($refund);

        if ($refundCents > $remaining) {
            throw new PostingValidationException(__('Refund cannot exceed the remaining :amount.', [
                'amount' => Money::fromCents(max(0, $remaining))->toDecimalString(),
            ]));
        }
    }

    /**
     * The posted deposit (or draft) a card refund has been put on, if any. A
     * refund already moved out of Undeposited Funds can't simply be voided:
     * the deposit would keep a line for a refund that no longer exists.
     */
    public function depositHolding(CustomerReceipt $receipt): ?Deposit
    {
        return Deposit::query()
            ->where('status', '!=', DepositStatus::Void->value)
            ->whereHas('lines', fn ($q) => $q->where('customer_receipt_id', $receipt->id))
            ->first();
    }

    private function assertRefundablePayment(CustomerReceipt $source, Cheque|CustomerReceipt $refund): void
    {
        if ($source->status !== ReceiptStatus::Posted) {
            throw new PostingValidationException(__('Only a posted payment can be refunded.'));
        }

        if ($source->isRefund() || (int) $source->amount_cents <= 0) {
            throw new PostingValidationException(__('A refund can\'t itself be refunded.'));
        }

        if ($source->isForeignCurrency()) {
            throw new PostingValidationException(__('Refunding a foreign-currency payment isn\'t supported yet.'));
        }

        if ($refund instanceof CustomerReceipt && (int) $refund->contact_id !== (int) $source->contact_id) {
            throw new PostingValidationException(__('A refund must go to the customer who made the payment.'));
        }
    }

    private function isVoid(Cheque|CustomerReceipt $refund): bool
    {
        return $refund instanceof Cheque
            ? $refund->status === ChequeStatus::Void
            : $refund->status === ReceiptStatus::Void;
    }
}
