<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\SaveReceipt;
use App\Enums\ReceiptStatus;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreReceiptRequest;
use App\Http\Requests\Api\V1\UpdateReceiptRequest;
use App\Http\Resources\Api\V1\ReceiptResource;
use App\Models\CustomerReceipt;
use App\Services\Posting\ReceiptPoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReceiptController extends ApiController
{
    use AppliesApiListFilters;

    public function __construct(protected ReceiptPoster $poster) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CustomerReceipt::query()->with('applications');

        $this->applyApiListFilters($query, $request, [
            'date_column' => 'receipt_date',
            'search' => ['receipt_no', 'reference', 'memo'],
            'sortable' => ['receipt_date', 'receipt_no', 'amount_cents', 'id'],
        ]);

        return ReceiptResource::collection($this->paginateApi($query, $request));
    }

    public function show(CustomerReceipt $receipt): ReceiptResource
    {
        return new ReceiptResource($receipt->load('applications'));
    }

    public function store(StoreReceiptRequest $request): JsonResponse
    {
        $data = $request->validated();

        $receipt = $this->posting(function () use ($data, $request): CustomerReceipt {
            $receipt = app(SaveReceipt::class)->handle($data);

            if (! $this->wantsDraft($request)) {
                $this->poster->post($receipt);
            }

            return $receipt->fresh(['applications']);
        });

        return (new ReceiptResource($receipt))->response()->setStatusCode(201);
    }

    public function update(UpdateReceiptRequest $request, CustomerReceipt $receipt): ReceiptResource
    {
        if ($receipt->status === ReceiptStatus::Void) {
            $this->conflict('A voided receipt cannot be edited.');
        }

        $wasPosted = $receipt->journal_entry_id !== null;

        $receipt = $this->posting(function () use ($request, $receipt, $wasPosted): CustomerReceipt {
            $receipt = app(SaveReceipt::class)->handle($request->validated(), $receipt);

            if ($wasPosted) {
                $this->poster->repost($receipt);
            }

            return $receipt->fresh(['applications']);
        });

        return new ReceiptResource($receipt);
    }

    /**
     * Post a draft, or repost edits to an already-posted receipt.
     */
    public function post(CustomerReceipt $receipt): ReceiptResource
    {
        if ($receipt->status === ReceiptStatus::Void) {
            $this->conflict('A voided receipt cannot be posted.');
        }

        $receipt = $this->posting(function () use ($receipt): CustomerReceipt {
            $receipt->journal_entry_id !== null
                ? $this->poster->repost($receipt)
                : $this->poster->post($receipt);

            return $receipt->fresh(['applications']);
        });

        return new ReceiptResource($receipt);
    }

    /**
     * Void a posted receipt (reversing JE) or hard-delete a draft.
     */
    public function destroy(CustomerReceipt $receipt): JsonResponse
    {
        if ($receipt->status === ReceiptStatus::Void) {
            $this->conflict('Receipt is already voided.');
        }

        if ($receipt->journal_entry_id !== null) {
            $this->posting(fn () => $this->poster->void($receipt));

            return (new ReceiptResource($receipt->fresh(['applications'])))->response()->setStatusCode(200);
        }

        $receipt->applications()->delete();
        $receipt->delete();

        return response()->json(null, 204);
    }
}
