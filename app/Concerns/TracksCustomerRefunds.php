<?php

namespace App\Concerns;

use App\Enums\ChequeStatus;
use App\Enums\ReceiptStatus;
use App\Models\Cheque;
use App\Models\CustomerReceipt;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A document holding customer credit that can be paid back to the customer:
 * a credit memo, or a customer payment with an unapplied (overpaid) remainder.
 *
 * A refund is either a cheque (DR Accounts Receivable, CR Bank) or a negative
 * customer receipt recording a card refund (DR Accounts Receivable, CR
 * Undeposited Funds or Bank), linked back here by {@see refundLinkColumn()}.
 * Draft refund cheques count, so a pending draft can't be issued twice.
 */
trait TracksCustomerRefunds
{
    /**
     * @return HasMany<Cheque, $this>
     */
    abstract public function refundCheques(): HasMany;

    /**
     * @return HasMany<CustomerReceipt, $this>
     */
    abstract public function refundReceipts(): HasMany;

    /**
     * The column on cheques and customer_receipts that points back at this document.
     */
    abstract public function refundLinkColumn(): string;

    /**
     * The credit this document makes available to refund, before any refunds.
     */
    abstract public function refundableBaseCents(): int;

    /**
     * Total already refunded across all non-void refund cheques (drafts
     * included) and card refunds. Always a positive amount of cents.
     *
     * $except leaves one refund out, so a guard can compare that refund
     * against the credit left by all the others. With both refund relations
     * eager-loaded (an API list), the sum is taken from them instead of the
     * database.
     */
    public function refundedCents(Cheque|CustomerReceipt|null $except = null): int
    {
        if ($except === null && $this->relationLoaded('refundCheques') && $this->relationLoaded('refundReceipts')) {
            return (int) $this->refundCheques->reject(fn (Cheque $c) => $c->status === ChequeStatus::Void)->sum('amount_cents')
                - (int) $this->refundReceipts->reject(fn (CustomerReceipt $r) => $r->status === ReceiptStatus::Void)->sum('amount_cents');
        }

        $byCheque = (int) $this->refundCheques()
            ->where('status', '!=', ChequeStatus::Void->value)
            ->when($except instanceof Cheque && $except->exists, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->sum('amount_cents');

        $byCard = (int) $this->refundReceipts()
            ->where('status', '!=', ReceiptStatus::Void->value)
            ->when($except instanceof CustomerReceipt && $except->exists, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->sum('amount_cents');

        return $byCheque - $byCard;
    }

    /**
     * Credit not yet refunded — the default and ceiling for a new refund.
     */
    public function remainingRefundableCents(): int
    {
        return max(0, $this->refundableBaseCents() - $this->refundedCents());
    }

    /**
     * Whether any refund (a draft or posted cheque, or a card refund) still
     * stands against this document.
     */
    public function hasLiveRefunds(): bool
    {
        return $this->refundCheques()->where('status', '!=', ChequeStatus::Void->value)->exists()
            || $this->refundReceipts()->where('status', '!=', ReceiptStatus::Void->value)->exists();
    }
}
