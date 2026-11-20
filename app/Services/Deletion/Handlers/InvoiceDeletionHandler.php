<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Enums\EstimateStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\Deletion\DeletionBlocker;
use App\Services\Deletion\DeletionHandler;
use App\Services\Deletion\DeletionWork;
use App\Services\Deletion\SnapshotValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deleting an invoice. A payment applied to it (whatever that payment's
 * state) or a balance closed against a credit blocks; its lines, reminder log
 * and payment requests go with it; the estimate it was converted from, the
 * time entries billed on it and refunds started from it are freed.
 */
final class InvoiceDeletionHandler implements DeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::Invoice;
    }

    public function journalEntryIds(Model $document): array
    {
        $entryId = $document->getAttribute('journal_entry_id');

        return $entryId !== null ? [(int) $entryId] : [];
    }

    public function blockers(Model $document): array
    {
        $invoice = $this->own($document);

        $company = $invoice->company;
        $blockers = [];

        $applications = DB::table('receipt_applications as a')
            ->join('customer_receipts as r', 'r.id', '=', 'a.customer_receipt_id')
            ->where('a.invoice_id', $invoice->id)
            ->orderBy('r.id')
            ->get(['r.id', 'r.receipt_no', 'r.deleted_at']);

        foreach ($applications->unique('id') as $receipt) {
            $blockers[] = new DeletionBlocker(
                __('Receipt :no is applied to this invoice. Delete that receipt, or take this invoice off it, first.', ['no' => (string) $receipt->receipt_no]),
                $receipt->deleted_at === null ? route('receipts.show', ['company' => $company->slug, 'receipt' => $receipt->id]) : null,
            );
        }

        if ((int) $invoice->reconciled_cents > 0) {
            $blockers[] = new DeletionBlocker(__('This invoice was closed against a customer credit (Close settled balance). Reopen it first.'));
        }

        return $blockers;
    }

    public function warnings(Model $document): array
    {
        return [];
    }

    public function taxCodeIds(Model $document): array
    {
        $invoice = $this->own($document);

        return $this->lineTaxCodes($invoice->lines()->get(['tax_code_id', 'secondary_tax_code_id'])->all());
    }

    public function summary(Model $document): array
    {
        $invoice = $this->own($document);

        return [
            'document_number' => $invoice->invoice_no,
            'document_date' => SnapshotValue::date($invoice->invoice_date),
            'contact_name' => $invoice->contact?->display_name,
            'total_cents' => (int) $invoice->total_cents,
            'currency_code' => $invoice->currency_code,
        ];
    }

    public function snapshot(Model $document): array
    {
        $invoice = $this->own($document);

        $invoice->loadMissing('contact', 'lines.account', 'lines.item', 'lines.taxCode', 'lines.secondaryTaxCode');

        return [
            'number' => $invoice->invoice_no,
            'date' => SnapshotValue::date($invoice->invoice_date),
            'due_date' => SnapshotValue::date($invoice->due_date),
            'status' => $invoice->status->value,
            'contact_id' => (int) $invoice->contact_id,
            'contact_name' => $invoice->contact?->display_name,
            'memo' => $invoice->memo,
            'customer_po' => $invoice->customer_po,
            'currency_code' => $invoice->currency_code,
            'fx_rate' => $invoice->fx_rate !== null ? (string) $invoice->fx_rate : null,
            'subtotal_cents' => (int) $invoice->subtotal_cents,
            'tax_cents' => (int) $invoice->tax_cents,
            'total_cents' => (int) $invoice->total_cents,
            'amount_paid_cents' => (int) $invoice->amount_paid_cents,
            'posted_at' => SnapshotValue::stamp($invoice->posted_at),
            'voided_at' => SnapshotValue::stamp($invoice->voided_at),
            'lines' => $invoice->lines->sortBy('line_order')->values()->map(fn (InvoiceLine $line): array => [
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
        $invoice = $this->own($document);

        $work->contact((int) $invoice->contact_id);

        // An estimate converted to this invoice can be converted again, unless
        // it also became a sales order.
        foreach (DB::table('estimates')->where('converted_invoice_id', $invoice->id)->get(['id', 'converted_sales_order_id']) as $estimate) {
            DB::table('estimates')->where('id', $estimate->id)->update(array_filter([
                'converted_invoice_id' => null,
                'status' => $estimate->converted_sales_order_id === null ? EstimateStatus::Accepted->value : false,
                'updated_at' => now(),
            ], fn ($value) => $value !== false));
        }

        // Hours billed on it are unbilled again.
        DB::table('time_entries')->where('invoice_id', $invoice->id)->update(['invoice_id' => null, 'updated_at' => now()]);

        // A refund started from this invoice keeps its own credit memo or payment link.
        DB::table('cheques')->where('from_invoice_id', $invoice->id)->update(['from_invoice_id' => null]);
        DB::table('customer_receipts')->where('from_invoice_id', $invoice->id)->update(['from_invoice_id' => null]);

        DB::table('invoice_reminder_logs')->where('invoice_id', $invoice->id)->delete();
        DB::table('invoice_payment_requests')->where('invoice_id', $invoice->id)->delete();
        DB::table('invoice_lines')->where('invoice_id', $invoice->id)->delete();

        $invoice->forceDelete();
    }

    public function afterPurge(DeletionWork $work, int $companyId): void {}

    /**
     * @param  list<Model>  $lines
     * @return list<int>
     */
    private function lineTaxCodes(array $lines): array
    {
        $ids = [];

        foreach ($lines as $line) {
            foreach (['tax_code_id', 'secondary_tax_code_id'] as $column) {
                if ($line->getAttribute($column) !== null) {
                    $ids[] = (int) $line->getAttribute($column);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function own(Model $document): Invoice
    {
        if (! $document instanceof Invoice) {
            throw new InvalidArgumentException('Expected a Invoice, got '.$document::class.'.');
        }

        return $document;
    }
}
