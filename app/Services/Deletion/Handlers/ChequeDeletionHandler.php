<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Enums\SlipType;
use Illuminate\Database\Eloquent\Model;

/**
 * Deleting a cheque, refund cheques included. A finalized T4A year it feeds
 * blocks. Its number stays reserved on its bank account
 * (DocumentNumberGenerator::nextChequeNumber() skips it), since the paper
 * cheque may already be written.
 */
final class ChequeDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::Cheque;
    }

    protected function lines(): array
    {
        return ['cheque_lines', 'cheque_id'];
    }

    protected function contactColumn(): string
    {
        return 'payee_contact_id';
    }

    protected function totalColumn(): string
    {
        return 'amount_cents';
    }

    public function blockers(Model $document): array
    {
        $isRefund = $document->getAttribute('credit_memo_id') !== null || $document->getAttribute('refunded_receipt_id') !== null;

        return $document->getAttribute('payee_contact_id') !== null && ! $isRefund
            ? $this->finalizedSlipBlocker($document, SlipType::T4a, $document->getAttribute('cheque_date'))
            : [];
    }

    public function summary(Model $document): array
    {
        return [...parent::summary($document), 'bank_account_id' => $document->getAttribute('bank_account_id') !== null ? (int) $document->getAttribute('bank_account_id') : null];
    }
}
