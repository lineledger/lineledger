<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Enums\SlipType;
use App\Services\Deletion\DeletionWork;
use App\Services\Deletion\InboxPromotion;
use Illuminate\Database\Eloquent\Model;

/**
 * Deleting an expense (a card or cash purchase). A finalized T4A year it
 * feeds blocks; the inbox document it was made from goes back to review.
 */
final class ExpenseDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::Expense;
    }

    protected function lines(): array
    {
        return ['expense_lines', 'expense_id'];
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
        return $document->getAttribute('payee_contact_id') !== null
            ? $this->finalizedSlipBlocker($document, SlipType::T4a, $document->getAttribute('expense_date'))
            : [];
    }

    protected function beforePurge(Model $document, DeletionWork $work): void
    {
        InboxPromotion::release(['expense'], (int) $document->getKey());
    }
}
