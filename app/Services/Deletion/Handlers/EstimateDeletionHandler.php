<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;

/**
 * Deleting an estimate. It never posts; the invoice or sales order it became
 * is its own document and stays.
 */
final class EstimateDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::Estimate;
    }

    protected function lines(): array
    {
        return ['estimate_lines', 'estimate_id'];
    }
}
