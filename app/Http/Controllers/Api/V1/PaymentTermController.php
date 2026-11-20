<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MasterData\SavePaymentTerm;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StorePaymentTermRequest;
use App\Http\Requests\Api\V1\UpdatePaymentTermRequest;
use App\Http\Resources\Api\V1\PaymentTermResource;
use App\Models\PaymentTerm;
use App\Services\References\PaymentTermReferenceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentTermController extends ApiController
{
    use AppliesApiListFilters;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PaymentTerm::query();

        $this->applyApiListFilters($query, $request, [
            'status_column' => null,
            'search' => ['name'],
            'sortable' => ['name', 'days', 'id'],
            'default_sort' => ['days', 'asc'],
        ]);

        return PaymentTermResource::collection($this->paginateApi($query, $request));
    }

    public function show(PaymentTerm $paymentTerm): PaymentTermResource
    {
        return new PaymentTermResource($paymentTerm);
    }

    public function store(StorePaymentTermRequest $request): JsonResponse
    {
        $term = app(SavePaymentTerm::class)->handle($request->validated());

        return (new PaymentTermResource($term))->response()->setStatusCode(201);
    }

    public function update(UpdatePaymentTermRequest $request, PaymentTerm $paymentTerm): PaymentTermResource
    {
        $term = app(SavePaymentTerm::class)->handle($request->validated(), $paymentTerm);

        return new PaymentTermResource($term);
    }

    /**
     * Delete unused payment terms. Terms a document or a contact still names
     * are refused; deactivate them instead.
     */
    public function destroy(PaymentTerm $paymentTerm): JsonResponse
    {
        $this->refuseWhileReferenced(PaymentTermReferenceRegistry::class, (int) $paymentTerm->id, __('payment terms'));

        $this->deleteListRecord($paymentTerm);

        return response()->json(null, 204);
    }
}
