<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;

/**
 * Deleting a transfer between two accounts.
 */
final class TransferDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::Transfer;
    }

    protected function lines(): ?array
    {
        return null;
    }

    protected function contactColumn(): ?string
    {
        return null;
    }

    protected function totalColumn(): string
    {
        return 'from_amount_cents';
    }
}
