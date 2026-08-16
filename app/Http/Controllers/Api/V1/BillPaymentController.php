<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Purchasing\SaveBillPayment;
use App\Enums\BillPaymentStatus;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreBillPaymentRequest;
use App\Http\Requests\Api\V1\UpdateBillPaymentRequest;
use App\Http\Resources\Api\V1\BillPaymentResource;
use App\Models\BillPayment;
use App\Services\Posting\BillPaymentPoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillPaymentController extends ApiController
{
    use AppliesApiListFilters;

    public function __construct(protected BillPaymentPoster $poster) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = BillPayment::query()->with('applications');

        $this->applyApiListFilters($query, $request, [
            'date_column' => 'payment_date',
            'search' => ['payment_no', 'reference', 'memo'],
            'sortable' => ['payment_date', 'payment_no', 'amount_cents', 'id'],
        ]);

        return BillPaymentResource::collection($this->paginateApi($query, $request));
    }

    public function show(BillPayment $billPayment): BillPaymentResource
    {
        return new BillPaymentResource($billPayment->load('applications'));
    }

    public function store(StoreBillPaymentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $payment = $this->posting(function () use ($data, $request): BillPayment {
            $payment = app(SaveBillPayment::class)->handle($data);

            if (! $this->wantsDraft($request)) {
                $this->poster->post($payment);
            }

            return $payment->fresh(['applications']);
        });

        return (new BillPaymentResource($payment))->response()->setStatusCode(201);
    }

    public function update(UpdateBillPaymentRequest $request, BillPayment $billPayment): BillPaymentResource
    {
        if ($billPayment->status === BillPaymentStatus::Void) {
            $this->conflict('A voided payment cannot be edited.');
        }

        $wasPosted = $billPayment->journal_entry_id !== null;

        $billPayment = $this->posting(function () use ($request, $billPayment, $wasPosted): BillPayment {
            $billPayment = app(SaveBillPayment::class)->handle($request->validated(), $billPayment);

            if ($wasPosted) {
                $this->poster->repost($billPayment);
            }

            return $billPayment->fresh(['applications']);
        });

        return new BillPaymentResource($billPayment);
    }

    /**
     * Post a draft, or repost edits to an already-posted payment.
     */
    public function post(BillPayment $billPayment): BillPaymentResource
    {
        if ($billPayment->status === BillPaymentStatus::Void) {
            $this->conflict('A voided payment cannot be posted.');
        }

        $billPayment = $this->posting(function () use ($billPayment): BillPayment {
            $billPayment->journal_entry_id !== null
                ? $this->poster->repost($billPayment)
                : $this->poster->post($billPayment);

            return $billPayment->fresh(['applications']);
        });

        return new BillPaymentResource($billPayment);
    }

    /**
     * Void a posted payment (reversing JE, restoring bill balances) or
     * hard-delete a draft.
     */
    public function destroy(BillPayment $billPayment): JsonResponse
    {
        if ($billPayment->status === BillPaymentStatus::Void) {
            $this->conflict('Payment is already voided.');
        }

        if ($billPayment->journal_entry_id !== null) {
            $this->posting(fn () => $this->poster->void($billPayment));

            return (new BillPaymentResource($billPayment->fresh(['applications'])))->response()->setStatusCode(200);
        }

        $billPayment->applications()->delete();
        $billPayment->delete();

        return response()->json(null, 204);
    }
}
