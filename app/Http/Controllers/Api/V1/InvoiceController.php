<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\RecordCustomerRefund;
use App\Actions\Sales\SaveInvoice;
use App\Enums\InvoiceStatus;
use App\Enums\RefundMethod;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\RefundInvoiceRequest;
use App\Http\Requests\Api\V1\StoreInvoiceRequest;
use App\Http\Requests\Api\V1\UpdateInvoiceRequest;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Http\Resources\Api\V1\ReceiptResource;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Services\EditLocks\EditLockManager;
use App\Services\Posting\InvoicePoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvoiceController extends ApiController
{
    use AppliesApiListFilters;

    public function __construct(protected InvoicePoster $poster) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Invoice::query()->with('lines');

        $this->applyApiListFilters($query, $request, [
            'date_column' => 'invoice_date',
            'search' => ['invoice_no', 'memo'],
            'sortable' => ['invoice_date', 'due_date', 'invoice_no', 'total_cents', 'id'],
        ]);

        return InvoiceResource::collection($this->paginateApi($query, $request));
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load('lines'));
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $invoice = $this->posting(function () use ($data, $request): Invoice {
            $invoice = app(SaveInvoice::class)->handle($data);

            if (! $this->wantsDraft($request)) {
                $this->poster->post($invoice);
            }

            return $invoice->fresh(['lines']);
        });

        return (new InvoiceResource($invoice))->response()->setStatusCode(201);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): InvoiceResource
    {
        if ($invoice->status === InvoiceStatus::Void) {
            $this->conflict('A voided invoice cannot be edited.');
        }

        $wasPosted = $invoice->journal_entry_id !== null;

        $invoice = $this->posting(function () use ($request, $invoice, $wasPosted): Invoice {
            $invoice = app(SaveInvoice::class)->handle($request->validated(), $invoice);

            if ($wasPosted) {
                $this->poster->repost($invoice);
            }

            return $invoice->fresh(['lines']);
        });

        return new InvoiceResource($invoice);
    }

    /**
     * Post a draft, or repost edits to an already-posted invoice.
     */
    public function post(Invoice $invoice): InvoiceResource
    {
        if ($invoice->status === InvoiceStatus::Void) {
            $this->conflict('A voided invoice cannot be posted.');
        }

        $invoice = $this->posting(function () use ($invoice): Invoice {
            $invoice->journal_entry_id !== null
                ? $this->poster->repost($invoice)
                : $this->poster->post($invoice);

            return $invoice->fresh(['lines']);
        });

        return new InvoiceResource($invoice);
    }

    /**
     * Void a posted invoice (reversing JE) or hard-delete a draft.
     */
    public function destroy(Invoice $invoice): JsonResponse
    {
        if ($invoice->status === InvoiceStatus::Void) {
            $this->conflict('Invoice is already voided.');
        }

        if ($invoice->journal_entry_id !== null) {
            $this->posting(fn () => $this->poster->void($invoice));

            return (new InvoiceResource($invoice->fresh(['lines'])))->response()->setStatusCode(200);
        }

        $invoice->lines()->delete();
        $invoice->delete();

        return response()->json(null, 204);
    }

    /**
     * Refund a customer's overpayment from this invoice — the API equivalent of
     * the invoice's "Refund to client → by credit card": pays back the unapplied
     * credit of refunded_receipt_id (a payment by this invoice's customer) as a
     * posted NEGATIVE customer receipt (DR Accounts Receivable, CR the deposit
     * account), linked to that payment and to this invoice. The invoice itself
     * is unchanged. Returns the created receipt.
     */
    public function refund(RefundInvoiceRequest $request, Invoice $invoice, RecordCustomerRefund $refunds, EditLockManager $locks): JsonResponse
    {
        if (! in_array($invoice->status, [InvoiceStatus::Posted, InvoiceStatus::Partial, InvoiceStatus::Paid], true)) {
            $this->conflict('Only a posted invoice can issue a refund.');
        }

        $data = $request->validated();
        $payment = CustomerReceipt::query()->findOrFail((int) $data['refunded_receipt_id']);

        // The refund spends the payment's credit: refused (423) while someone is
        // editing that payment in the web app, like any other write to it.
        $receipt = $locks->guardWrite($payment, null, fn (): CustomerReceipt => $this->posting(
            fn (): CustomerReceipt => $refunds->handle($payment, [
                'method' => RefundMethod::Card,
                'amount_cents' => (int) $data['amount_cents'],
                'refund_date' => $data['refund_date'],
                'deposit_to_account_id' => (int) $data['deposit_to_account_id'],
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'receipt_no' => $data['receipt_no'] ?? null,
                'memo' => $data['memo'] ?? null,
            ], $invoice),
        ));

        return (new ReceiptResource($receipt->load(ReceiptResource::RELATIONS)))->response()->setStatusCode(201);
    }
}
