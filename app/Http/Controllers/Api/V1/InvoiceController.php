<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\SaveInvoice;
use App\Enums\InvoiceStatus;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreInvoiceRequest;
use App\Http\Requests\Api\V1\UpdateInvoiceRequest;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Models\Invoice;
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
}
