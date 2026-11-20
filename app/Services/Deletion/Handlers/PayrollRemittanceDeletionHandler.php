<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use Illuminate\Database\Eloquent\Model;

/**
 * Deleting a payroll remittance: the payment to CRA, Revenu Québec or the
 * workers' compensation board comes out of the bank and the payables it
 * cleared owe again. Pay runs it covered can be deleted once it is gone.
 */
final class PayrollRemittanceDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::PayrollRemittance;
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
        return 'total_cents';
    }

    public function summary(Model $document): array
    {
        $summary = parent::summary($document);
        $agency = $document->getAttribute('agency');

        return [
            ...$summary,
            'contact_name' => is_object($agency) && method_exists($agency, 'label') ? (string) $agency->label() : $summary['contact_name'],
        ];
    }

    protected function extraSnapshot(Model $document): array
    {
        return [
            'memo' => $this->stringOrNull($document->getAttribute('notes')),
            'period_start' => $this->stringOrNull($document->getAttribute('period_start')?->toDateString()),
            'period_end' => $this->stringOrNull($document->getAttribute('period_end')?->toDateString()),
            'breakdown' => $document->getAttribute('breakdown'),
        ];
    }
}
