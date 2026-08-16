<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MasterData\SavePaymentMethod;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StorePaymentMethodRequest;
use App\Http\Requests\Api\V1\UpdatePaymentMethodRequest;
use App\Http\Resources\Api\V1\PaymentMethodResource;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentMethodController extends ApiController
{
    use AppliesApiListFilters;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PaymentMethod::query();

        $this->applyApiListFilters($query, $request, [
            'status_column' => null,
            'search' => ['name'],
            'sortable' => ['name', 'id'],
            'default_sort' => ['name', 'asc'],
        ]);

        return PaymentMethodResource::collection($this->paginateApi($query, $request));
    }

    public function show(PaymentMethod $paymentMethod): PaymentMethodResource
    {
        return new PaymentMethodResource($paymentMethod);
    }

    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $method = app(SavePaymentMethod::class)->handle($request->validated());

        return (new PaymentMethodResource($method))->response()->setStatusCode(201);
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): PaymentMethodResource
    {
        $method = app(SavePaymentMethod::class)->handle($request->validated(), $paymentMethod);

        return new PaymentMethodResource($method);
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        $paymentMethod->delete();

        return response()->json(null, 204);
    }
}
