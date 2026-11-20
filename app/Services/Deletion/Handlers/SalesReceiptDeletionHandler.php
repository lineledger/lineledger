<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Models\SalesReceipt;
use App\Models\SalesReceiptLine;
use App\Services\Deletion\DeletionBlocker;
use App\Services\Deletion\DeletionHandler;
use App\Services\Deletion\DeletionWork;
use App\Services\Deletion\SnapshotValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deleting a sales receipt. A deposit holding it (live or voided) blocks: the
 * deposit's own record of the money must change first. Its lines go with it.
 */
final class SalesReceiptDeletionHandler implements DeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::SalesReceipt;
    }

    public function journalEntryIds(Model $document): array
    {
        $entryId = $document->getAttribute('journal_entry_id');

        return $entryId !== null ? [(int) $entryId] : [];
    }

    public function blockers(Model $document): array
    {
        $receipt = $this->own($document);

        $company = $receipt->company;

        return DB::table('deposit_lines as l')
            ->join('deposits as d', 'd.id', '=', 'l.deposit_id')
            ->where('l.sales_receipt_id', $receipt->id)
            ->orderBy('d.id')
            ->get(['d.id', 'd.deposit_no', 'd.deleted_at'])
            ->unique('id')
            ->map(fn ($deposit): DeletionBlocker => new DeletionBlocker(
                __('Deposit :no holds this :document. Take it off that deposit, or delete the deposit, first.', ['no' => (string) $deposit->deposit_no, 'document' => __('sales receipt')]),
                $deposit->deleted_at === null ? route('deposits.show', ['company' => $company->slug, 'deposit' => $deposit->id]) : null,
            ))
            ->values()
            ->all();
    }

    public function warnings(Model $document): array
    {
        return [];
    }

    public function taxCodeIds(Model $document): array
    {
        $receipt = $this->own($document);

        $ids = [];

        foreach ($receipt->lines()->get(['tax_code_id', 'secondary_tax_code_id']) as $line) {
            foreach (['tax_code_id', 'secondary_tax_code_id'] as $column) {
                if ($line->getAttribute($column) !== null) {
                    $ids[] = (int) $line->getAttribute($column);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    public function summary(Model $document): array
    {
        $receipt = $this->own($document);

        return [
            'document_number' => $receipt->sales_receipt_no,
            'document_date' => SnapshotValue::date($receipt->receipt_date),
            'contact_name' => $receipt->contact?->display_name,
            'total_cents' => (int) $receipt->total_cents,
            'currency_code' => $receipt->currency_code,
        ];
    }

    public function snapshot(Model $document): array
    {
        $receipt = $this->own($document);

        $receipt->loadMissing('contact', 'depositToAccount', 'paymentMethod', 'lines.account', 'lines.item', 'lines.taxCode', 'lines.secondaryTaxCode');

        return [
            'number' => $receipt->sales_receipt_no,
            'date' => SnapshotValue::date($receipt->receipt_date),
            'status' => $receipt->status->value,
            'contact_id' => $receipt->contact_id !== null ? (int) $receipt->contact_id : null,
            'contact_name' => $receipt->contact?->display_name,
            'deposit_to' => $receipt->depositToAccount?->name,
            'payment_method' => $receipt->paymentMethod?->name,
            'reference' => $receipt->reference,
            'memo' => $receipt->memo,
            'currency_code' => $receipt->currency_code,
            'subtotal_cents' => (int) $receipt->subtotal_cents,
            'tax_cents' => (int) $receipt->tax_cents,
            'total_cents' => (int) $receipt->total_cents,
            'posted_at' => SnapshotValue::stamp($receipt->posted_at),
            'voided_at' => SnapshotValue::stamp($receipt->voided_at),
            'lines' => $receipt->lines->sortBy('line_order')->values()->map(fn (SalesReceiptLine $line): array => [
                'description' => $line->description,
                'item' => $line->item?->name,
                'sku' => $line->item?->sku,
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'quantity' => (string) $line->quantity,
                'unit_price_cents' => (int) $line->unit_price_cents,
                'tax_code' => $line->taxCode?->code,
                'secondary_tax_code' => $line->secondaryTaxCode?->code,
                'line_total_cents' => (int) $line->line_total_cents,
            ])->all(),
        ];
    }

    public function purge(Model $document, DeletionWork $work): void
    {
        $receipt = $this->own($document);

        $work->contact($receipt->contact_id !== null ? (int) $receipt->contact_id : null);

        DB::table('sales_receipt_lines')->where('sales_receipt_id', $receipt->id)->delete();

        $receipt->forceDelete();
    }

    public function afterPurge(DeletionWork $work, int $companyId): void {}

    private function own(Model $document): SalesReceipt
    {
        if (! $document instanceof SalesReceipt) {
            throw new InvalidArgumentException('Expected a SalesReceipt, got '.$document::class.'.');
        }

        return $document;
    }
}
