<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tax\SaveTaxReturnPayment;
use App\Enums\TaxReturnPaymentStatus;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreTaxReturnPaymentRequest;
use App\Http\Requests\Api\V1\UpdateTaxReturnPaymentRequest;
use App\Http\Resources\Api\V1\TaxReturnPaymentResource;
use App\Models\TaxReturnPayment;
use App\Services\Posting\TaxReturnPaymentPoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaxReturnPaymentController extends ApiController
{
    use AppliesApiListFilters;

    public function __construct(protected TaxReturnPaymentPoster $poster) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TaxReturnPayment::query();

        $this->applyApiListFilters($query, $request, [
            'date_column' => 'payment_date',
            'search' => ['payment_no', 'reference'],
            'sortable' => ['payment_date', 'payment_no', 'total_cents', 'id'],
        ]);

        return TaxReturnPaymentResource::collection($this->paginateApi($query, $request));
    }

    public function show(TaxReturnPayment $taxReturnPayment): TaxReturnPaymentResource
    {
        return new TaxReturnPaymentResource($taxReturnPayment);
    }

    public function store(StoreTaxReturnPaymentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $payment = $this->posting(function () use ($data, $request): TaxReturnPayment {
            $payment = app(SaveTaxReturnPayment::class)->handle($data);

            if (! $this->wantsDraft($request)) {
                $this->poster->post($payment);
            }

            return $payment->fresh();
        });

        return (new TaxReturnPaymentResource($payment))->response()->setStatusCode(201);
    }

    /**
     * Edit a draft. Posted payments have no repost path — void and recreate.
     */
    public function update(UpdateTaxReturnPaymentRequest $request, TaxReturnPayment $taxReturnPayment): TaxReturnPaymentResource
    {
        if ($taxReturnPayment->status === TaxReturnPaymentStatus::Void) {
            $this->conflict('A voided tax payment cannot be edited.');
        }

        if ($taxReturnPayment->journal_entry_id !== null) {
            $this->conflict('This posted document cannot be edited; void and recreate.');
        }

        $payment = app(SaveTaxReturnPayment::class)->handle($request->validated(), $taxReturnPayment);

        return new TaxReturnPaymentResource($payment->fresh());
    }

    /**
     * Post a draft to the GL. No repost path.
     */
    public function post(TaxReturnPayment $taxReturnPayment): TaxReturnPaymentResource
    {
        if ($taxReturnPayment->status === TaxReturnPaymentStatus::Void) {
            $this->conflict('A voided tax payment cannot be posted.');
        }

        if ($taxReturnPayment->journal_entry_id !== null) {
            $this->conflict('This payment is already posted.');
        }

        $payment = $this->posting(function () use ($taxReturnPayment): TaxReturnPayment {
            $this->poster->post($taxReturnPayment);

            return $taxReturnPayment->fresh();
        });

        return new TaxReturnPaymentResource($payment);
    }

    /**
     * Void a posted payment (reversing JE) or hard-delete a draft.
     */
    public function destroy(TaxReturnPayment $taxReturnPayment): JsonResponse
    {
        if ($taxReturnPayment->status === TaxReturnPaymentStatus::Void) {
            $this->conflict('Tax payment is already voided.');
        }

        if ($taxReturnPayment->journal_entry_id !== null) {
            $this->posting(fn () => $this->poster->void($taxReturnPayment));

            return (new TaxReturnPaymentResource($taxReturnPayment->fresh()))->response()->setStatusCode(200);
        }

        $taxReturnPayment->delete();

        return response()->json(null, 204);
    }
}
