<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;

/**
 * Deleting a bank deposit. Its lines are its own, so the payments and sales
 * receipts it held return to Undeposited Funds and can be deposited again.
 */
final class DepositDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::Deposit;
    }

    protected function lines(): array
    {
        return ['deposit_lines', 'deposit_id'];
    }

    protected function contactColumn(): ?string
    {
        return null;
    }

    protected function totalColumn(): string
    {
        return 'amount_cents';
    }
}
