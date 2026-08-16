<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Purchasing\SaveBill;
use App\Enums\BillStatus;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreBillRequest;
use App\Http\Requests\Api\V1\UpdateBillRequest;
use App\Http\Resources\Api\V1\BillResource;
use App\Models\Bill;
use App\Services\Posting\BillPoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillController extends ApiController
{
    use AppliesApiListFilters;

    public function __construct(protected BillPoster $poster) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Bill::query()->with('lines');

        $this->applyApiListFilters($query, $request, [
            'date_column' => 'bill_date',
            'search' => ['bill_no', 'vendor_reference', 'memo'],
            'sortable' => ['bill_date', 'due_date', 'bill_no', 'total_cents', 'id'],
        ]);

        if ($request->filled('bill_type')) {
            $query->where('bill_type', $request->string('bill_type'));
        }

        return BillResource::collection($this->paginateApi($query, $request));
    }

    public function show(Bill $bill): BillResource
    {
        return new BillResource($bill->load('lines'));
    }

    public function store(StoreBillRequest $request): JsonResponse
    {
        $data = $request->validated();

        $bill = $this->posting(function () use ($data, $request): Bill {
            $bill = app(SaveBill::class)->handle($data);

            if (! $this->wantsDraft($request)) {
                $this->poster->post($bill);
            }

            return $bill->fresh(['lines']);
        });

        return (new BillResource($bill))->response()->setStatusCode(201);
    }

    public function update(UpdateBillRequest $request, Bill $bill): BillResource
    {
        if ($bill->status === BillStatus::Void) {
            $this->conflict('A voided bill cannot be edited.');
        }

        $wasPosted = $bill->journal_entry_id !== null;

        $bill = $this->posting(function () use ($request, $bill, $wasPosted): Bill {
            $bill = app(SaveBill::class)->handle($request->validated(), $bill);

            if ($wasPosted) {
                $this->poster->repost($bill);
            }

            return $bill->fresh(['lines']);
        });

        return new BillResource($bill);
    }

    /**
     * Post a draft, or repost edits to an already-posted bill.
     */
    public function post(Bill $bill): BillResource
    {
        if ($bill->status === BillStatus::Void) {
            $this->conflict('A voided bill cannot be posted.');
        }

        $bill = $this->posting(function () use ($bill): Bill {
            $bill->journal_entry_id !== null
                ? $this->poster->repost($bill)
                : $this->poster->post($bill);

            return $bill->fresh(['lines']);
        });

        return new BillResource($bill);
    }

    /**
     * Void a posted bill (reversing JE) or hard-delete a draft.
     */
    public function destroy(Bill $bill): JsonResponse
    {
        if ($bill->status === BillStatus::Void) {
            $this->conflict('Bill is already voided.');
        }

        if ($bill->journal_entry_id !== null) {
            $this->posting(fn () => $this->poster->void($bill));

            return (new BillResource($bill->fresh(['lines'])))->response()->setStatusCode(200);
        }

        $bill->lines()->delete();
        $bill->delete();

        return response()->json(null, 204);
    }
}
